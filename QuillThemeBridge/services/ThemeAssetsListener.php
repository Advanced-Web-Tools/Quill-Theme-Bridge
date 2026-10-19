<?php
namespace QuillThemeBridge\services;

use event\interfaces\{IEvent, IEventListener};
use URPC\api\events\BeforeRenderEvent;
use Quill\services\conversion\quillToUrpc\ConversionResult;
use ThemeEngine\service\LayoutBuilder;
use URPC\service\BlockIdentity;

/** Supplies source theme assets for ordinary Quill local copies and external previews. */
final class ThemeAssetsListener implements IEventListener
{
    private \WeakMap $handled;
    public function __construct(private readonly ThemeStyles $styles) { $this->handled = new \WeakMap(); }
    public function handle(IEvent $event): array
    {
        if (!$event instanceof BeforeRenderEvent || isset($this->handled[$event])) return [];
        $this->handled[$event] = true;
        $document = $event->getDocument(); $assets = ['styles' => [], 'scripts' => []]; $appearances = [];
        foreach ($this->styles->references($document) as $reference) {
            try {
                $appearance = $this->styles->describe($reference['themeKey'], $reference['pageKey']);
                $appearances[$reference['themeKey'] . ':' . json_encode([$appearance['bodyClasses'], $appearance['variables'], $appearance['language']], JSON_THROW_ON_ERROR)] = $appearance;
                $assets['styles'] = array_values(array_unique([...$assets['styles'], ...$appearance['styles']]));
                $assets['scripts'] = array_values(array_unique([...$assets['scripts'], ...$appearance['scripts']]));
            } catch (\Throwable $error) { error_log('QuillThemeBridge theme styles: ' . $error->getMessage()); }
        }
        $retained = ['styles' => [], 'scripts' => []];
        foreach (Documents::walk($document) as $block) {
            $descriptor = $block->getBlockDescriptor();
            if ($descriptor instanceof RuntimeDescriptor) foreach (['styles', 'scripts'] as $type) $retained[$type] = [...$retained[$type], ...$descriptor->retainedAssets[$type]];
        }
        foreach (['styles', 'scripts'] as $type) $assets[$type] = array_values(array_diff($assets[$type], $retained[$type]));
        if ($assets['styles'] !== [] || $assets['scripts'] !== []) (new RenderMetadata())->attach($document, new ConversionResult($document, $assets, ''));
        if (count($appearances) === 1 && LayoutBuilder::findSlot($document, 'content') === null) {
            $appearance = reset($appearances);
            if ($appearance['bodyClasses'] !== [] || $appearance['variables'] !== [] || $appearance['language'] !== null) {
                $block = LayoutBuilder::block('script', '', ['src' => '/awt_packages/QuillThemeBridge/assets/theme-style-runtime.js', 'defer' => true,
                    'data-quill-theme-style' => json_encode(array_intersect_key($appearance, array_flip(['bodyClasses', 'variables', 'language'])), JSON_THROW_ON_ERROR)]);
                foreach ((new BlockIdentity())->assignBlocks([$block], 'QuillThemeBridge/theme-style') as $block) $document->addBlock('quillThemeStyle', $block);
            }
        }
        return [];
    }
}
