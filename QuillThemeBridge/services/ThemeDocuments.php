<?php
namespace QuillThemeBridge\services;

use context\Context;
use ThemeEngine\api\{ThemeDefinition, ThemePageContext, SectionSource};
use ThemeEngine\service\{Engine, ThemeRegistry, LayoutBuilder};
use URPC\api\{URPCDocument};
use URPC\api\contracts\{IURPCDocument, IURPCBlock};
use URPC\service\BlockIdentity;

/** Composes public ThemeEngine services; final upstream classes are never replaced or subclassed. */
final class ThemeDocuments
{
    public function __construct(private readonly Engine $engine) {}
    public function selected(int $id): Engine
    {
        $record = $this->engine->registry->get($id);
        if (!$record->enabled) throw new \LogicException('Theme package is disabled.');
        foreach ($record->dependencies as $dependency) if (isset($dependency['status']) && $dependency['status'] !== 'installed') {
            throw new \LogicException('Unavailable theme dependency: ' . $dependency['name']);
        }
        return $this->engine->forTheme($id);
    }
    private function context(Engine $engine, string $page = ''): ThemePageContext
    {
        $theme = $engine->activeTheme();
        return new ThemePageContext(new Context($theme->root . '/', $theme->name, (string) $theme->packageId), $theme, $page, $engine->settings(), []);
    }
    public function page(int $themeId, string $pageKey): IURPCDocument
    {
        $selected = $this->selected($themeId);
        $assembled = Documents::copy($selected->createDocument($this->context($selected, $pageKey)));
        (new BlockIdentity())->assignDocument($assembled, $selected->activeTheme()->name . '/pages/' . $pageKey);
        $document = new URPCDocument(); $document->addSection('content');
        foreach (LayoutBuilder::findSlot($assembled, 'content')?->getBlockChildren() ?? [] as $block) $document->addBlock('content', $block);
        return $document;
    }
    private function source(Engine $engine, SectionSource $source, string $slot): array
    {
        $context = $this->context($engine);
        if ($source->file !== null) return $engine->cache->load(ThemeRegistry::resolveFile($context->theme->root, $source->file), $context->theme->name . '/' . $source->file);
        $document = new URPCDocument();
        foreach ($source->provider->createBlocks($context) as $block) {
            if (!$block instanceof IURPCBlock) throw new \UnexpectedValueException('Expected source URPC blocks.');
            $document->addBlock($slot, Documents::block($block));
        }
        (new BlockIdentity())->assignDocument($document, $context->theme->name . '/providers/' . $source->key);
        return array_values($document->getSections()[$slot] ?? []);
    }
    public function compose(IURPCDocument $content, array $retainedAssets = ['styles' => [], 'scripts' => []]): ?IURPCDocument
    {
        if (!$this->engine->hasActiveTheme()) return null;
        $engine = $this->selected($this->engine->activeTheme()->packageId);
        $definition = $engine->definition(); $enabled = $engine->globalSections() + ['content' => true];
        $slots = array_fill_keys(ThemeDefinition::SLOTS, []);
        foreach (ThemeDefinition::OPTIONAL as $slot) {
            if (!($enabled[$slot] ?? false)) continue;
            if (isset($definition->sections[$slot])) $slots[$slot] = $this->source($engine, $definition->sections[$slot], $slot);
            if (in_array($slot, ['side_l', 'side_r'], true)) {
                foreach ($engine->globalWidgets()[$slot] as $key) {
                    try { $slots[$slot] = [...$slots[$slot], ...$engine->widgets->render([$key], $this->context($engine), $slot)]; }
                    catch (\Throwable $error) { error_log('QuillThemeBridge widget ' . $key . ': ' . $error->getMessage()); }
                }
            }
        }
        $quillContent = Documents::copy($content); StylePriority::inline($quillContent);
        foreach ($quillContent->getSections() as $blocks) $slots['content'] = [...$slots['content'], ...array_values($blocks)];
        $layout = (new LayoutBuilder())->build($slots, $enabled, $engine->activeTheme()->name, $definition->stylesheets, $definition->scripts);
        foreach (Documents::walk($layout) as $parent) {
            $children = array_filter($parent->getBlockChildren(), static function ($block) use ($retainedAssets): bool {
                $attrs = $block->getBlockAttributes(); $source = $block->getBlockDescriptor()->getSourceKey();
                if (str_ends_with($source, '/layout/asset') && in_array($attrs['href'] ?? null, $retainedAssets['styles'], true)) return false;
                if (str_ends_with($source, '/layout/script') && in_array($attrs['src'] ?? null, $retainedAssets['scripts'], true)) return false;
                return true;
            });
            $parent->setBlockChildren($children);
        }
        return $layout;
    }
    public function part(int $themeId, string $slot): IURPCDocument
    {
        $engine = $this->selected($themeId);
        if (!in_array($slot, ThemeDefinition::SLOTS, true)) throw new \InvalidArgumentException('Unknown layout slot.');
        $source = $engine->definition()->sections[$slot] ?? null;
        $slots = array_fill_keys(ThemeDefinition::SLOTS, []);
        $slots[$slot] = $source === null ? [] : $this->source($engine, $source, $slot);
        $layout = (new LayoutBuilder())->build($slots, array_fill_keys(ThemeDefinition::SLOTS, true), $engine->activeTheme()->name, [], []);
        $document = new URPCDocument(); $document->addBlock('main', LayoutBuilder::findSlot($layout, $slot)); return $document;
    }
    public function widget(int $themeId, string $key): IURPCDocument
    {
        $engine = $this->selected($themeId);
        if (!isset($engine->widgets->all()[$key])) throw new \OutOfBoundsException('Unknown widget.');
        $document = new URPCDocument();
        foreach ($engine->widgets->render([$key], $this->context($engine), 'side_l') as $block) $document->addBlock('main', $block);
        return $document;
    }
}
