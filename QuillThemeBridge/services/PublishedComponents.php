<?php
namespace QuillThemeBridge\services;

use Quill\services\editor\ExternalPageOverrides;
use ThemeEngine\service\Engine;
use URPC\api\URPCDocument;
use URPC\api\contracts\{IURPCDocument, IURPCBlock};

/** Published component patches are scoped to a theme and its native slot/widget instance. */
final class PublishedComponents
{
    public function __construct(private readonly Resources $resources) {}

    /** Compare source content, never infer or migrate a UID from a tree position. */
    private static function structure(IURPCBlock $block): array
    {
        $descriptor = $block->getBlockDescriptor(); $props = $descriptor->getBlockProps();
        unset($props[ThemeStyles::PROPERTY]);
        return [$descriptor->getBlockName(), $descriptor->getBlockRenderer(), $props,
            $block->getBlockAttributes(), $block->getBlockContent(), array_map(self::structure(...), array_values($block->getBlockChildren()))];
    }
    private static function changedFields(array $target, array $baseline, array $published): array
    {
        foreach ($baseline as $key => $value) if (!array_key_exists($key, $published)) unset($target[$key]);
        foreach ($published as $key => $value) if (!array_key_exists($key, $baseline) || $baseline[$key] !== $value) $target[$key] = $value;
        return $target;
    }

    public function apply(IURPCDocument $document, Engine $engine, callable $diagnostic, ?string $pageKey = null): IURPCDocument
    {
        if (!class_exists(ExternalPageOverrides::class) || !method_exists(ExternalPageOverrides::class, 'componentSource')) return $document;
        $document = Documents::copy($document); $theme = $engine->activeTheme();
        $overrides = new ExternalPageOverrides(); $sources = []; $baselines = []; $used = [];
        $apply = function (IURPCBlock $target, string $type, string $key) use ($theme, $overrides, &$sources, &$baselines, &$used, $diagnostic): IURPCBlock {
            $resourceKey = Keys::resource($theme->packageId, $type, $key);
            try {
                if (!array_key_exists($resourceKey, $sources)) {
                    $owner = $overrides->find($resourceKey, 'component');
                    $sources[$resourceKey] = null;
                    if ($owner !== null && $owner->published_snapshot_id !== null) {
                        $source = $this->resources->component($theme->packageId, $type, $key);
                        $roots = [];
                        foreach ($source->document->getSections() as $blocks) $roots = [...$roots, ...array_values($blocks)];
                        if (count($roots) !== 1) throw new \LogicException('External component requires one root block.');
                        $baselines[$resourceKey] = $roots[0];
                        $sources[$resourceKey] = $overrides->componentSource($source);
                    }
                }
                if ($sources[$resourceKey] === null) return $target;
                $uid = $target->getBlockDescriptor()->getBlockUid();
                if ($uid === '') throw new \LogicException('Native component instance has no URPC UID.');
                $instanceKey = $resourceKey . ':' . $uid;
                $published = (new \Quill\services\conversion\quillToUrpc\QuillToUrpcConverter())->convert(
                    new ComponentInstanceSource($sources[$resourceKey], $instanceKey));
                StylePriority::inline($published->document);
                foreach (Documents::walk($published->document) as $block) {
                    $identity = SourceIdentity::read($published->blockSources[spl_object_id($block)] ?? null);
                    if ($identity !== null) {
                        $block->getBlockDescriptor()->setBlockUid($identity['uid']);
                        $block->getBlockDescriptor()->setSourceKey($identity['source']);
                    }
                }
                $extras = []; $sourceTarget = Documents::block($target);
                if ($type === 'part') {
                    $children = [];
                    foreach ($sourceTarget->getBlockChildren() as $child) {
                        $widgetKey = $child->getBlockAttributes()['data-te-widget'] ?? null;
                        if (is_string($widgetKey) && $child->getBlockDescriptor()->getSourceKey() === $theme->name . '/widgets/' . $key . '/' . $widgetKey) $extras[] = $child;
                        else $children[] = $child;
                    }
                    $sourceTarget->setBlockChildren($children);
                }
                $scope = new URPCDocument(); $scope->addBlock('main', $target);
                [$replacement] = (new Replacements())->apply($scope, $published, $diagnostic, $theme->packageId, '');
                // The native wrapper is explicitly identified by slot/widget key. Its UID can
                // differ by placement or by additional widgets in a sidebar. Apply its own
                // edited fields while retaining the actual instance's UID and complete children.
                $root = null;
                foreach ($published->document->getSections() as $blocks) foreach ($blocks as $block) {
                    if ($root !== null) throw new \LogicException('External component has multiple root blocks.');
                    $root = $block;
                }
                if ($root === null) return $target;
                $replacement = array_values($replacement->getBlocks('main'))[0];
                $instance = Documents::block($root);
                $baseline = $baselines[$resourceKey];
                $descriptor = clone $target->getBlockDescriptor(); $edited = $root->getBlockDescriptor(); $base = $baseline->getBlockDescriptor();
                if ($edited->getBlockName() !== $base->getBlockName()) $descriptor->setBlockName($edited->getBlockName());
                if ($edited->getBlockRenderer() !== $base->getBlockRenderer()) $descriptor->setBlockRenderer($edited->getBlockRenderer());
                $props = self::changedFields($descriptor->getBlockProps(), $base->getBlockProps(), $edited->getBlockProps());
                if (isset($edited->getBlockProps()[ThemeStyles::PROPERTY])) $props[ThemeStyles::PROPERTY] = $edited->getBlockProps()[ThemeStyles::PROPERTY];
                $descriptor->setBlockProps($props); $instance->setBlockDescriptor($descriptor);
                $instance->setBlockAttributes(self::changedFields($target->getBlockAttributes(), $baseline->getBlockAttributes(), $root->getBlockAttributes()));
                if ($root->getBlockContent() === $baseline->getBlockContent()) $instance->setBlockContent($target->getBlockContent());
                // Identical provider instances can have placement-specific native UIDs. The
                // explicit resource key identifies the complete source, not a positional UID map.
                // Context-dependent trees keep conservative per-UID replacements instead.
                $sameSource = json_encode(self::structure($sourceTarget), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)
                    === json_encode(self::structure($baseline), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                $instance->setBlockChildren($sameSource ? [...$instance->getBlockChildren(), ...$extras] : $replacement->getBlockChildren());
                $instance->getBlockDescriptor()->setBlockUid($target->getBlockDescriptor()->getBlockUid());
                $instance->getBlockDescriptor()->setSourceKey($target->getBlockDescriptor()->getSourceKey());
                $used[$instanceKey] = $published;
                return $instance;
            } catch (\Throwable $error) {
                $sources[$resourceKey] = null;
                $diagnostic('Component ' . $resourceKey . ': ' . $error->getMessage());
                return $target;
            }
        };
        // Parts first; widgets remain in their configured slots, including widgets added alongside a part.
        foreach (['part', 'widget'] as $type) {
            $visit = function (IURPCBlock $block) use (&$visit, $type, $engine, $theme, $apply, $pageKey): IURPCBlock {
                $attrs = $block->getBlockAttributes(); $source = $block->getBlockDescriptor()->getSourceKey();
                if ($type === 'part') {
                    $slot = $attrs['data-te-section'] ?? null;
                    $localSections = $pageKey === null ? [] : ($engine->definition()->pages[$pageKey]->sections ?? []);
                    if (is_string($slot) && !array_key_exists($slot, $localSections)
                        && isset($engine->definition()->sections[$slot]) && $source === $theme->name . '/slot/' . $slot) {
                        $block = $apply($block, 'part', $slot);
                    }
                } else {
                    $key = $attrs['data-te-widget'] ?? null;
                    if (is_string($key) && isset($engine->widgets->all()[$key])
                        && in_array($source, [$theme->name . '/widgets/side_l/' . $key, $theme->name . '/widgets/side_r/' . $key], true)) {
                        // Each intentional placement is an independent target scope.
                        return $apply($block, 'widget', $key);
                    }
                }
                $block->setBlockChildren(array_map($visit, $block->getBlockChildren()));
                return $block;
            };
            $sections = [];
            foreach ($document->getSections() as $name => $blocks) $sections[$name] = array_map($visit, $blocks);
            $document->fromArray($sections);
        }
        foreach ($used as $published) (new RenderMetadata())->attach($document, $published);
        return $document;
    }
}
