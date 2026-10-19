<?php
namespace QuillThemeBridge\services;

use event\interfaces\{IEvent, IEventListener};
use context\Context;
use ThemeEngine\service\Engine;
use ThemeEngine\api\events\PageReadyEvent;
use Quill\api\events\{ExternalPagesRequestEvent, ExternalComponentsRequestEvent, ExternalColorPalettesRequestEvent, PageDocumentRequestEvent};
use QuillThemeBridge\api\LinkRepository;

final class BridgeListener implements IEventListener
{
    private \WeakMap $handled;
    private \WeakMap $composed;
    public function __construct(private readonly Engine $engine, private readonly Context $context,
        private readonly LinkRepository $links, private readonly Resources $resources,
        private readonly PublishedPageConversion $published = new PublishedPageConversion(), private readonly PaletteCatalog $palettes = new PaletteCatalog()) { $this->handled = new \WeakMap(); $this->composed = new \WeakMap(); }
    private function diagnostic(string $message): void { error_log('QuillThemeBridge: ' . $message); }
    private function pageStylePriority(PageDocumentRequestEvent $event, \URPC\api\contracts\IURPCDocument $document): void
    {
        $source = null;
        if ($event->page->source_type === 'urpc') $source = (new \Quill\services\editor\ExternalPageOverrides())->definition($event->page);
        $result = $this->published->convert($event->page->getKey(), $source, publicScope: true);
        if ($result === null || $result->scopedCss === '') return;
        // Retain the original Quill render metadata; add only the priority CSS counterpart.
        (new RenderMetadata())->attach($document, new \Quill\services\conversion\quillToUrpc\ConversionResult($document,
            ['styles' => [], 'scripts' => []], $result->scopedCss));
    }
    public function handle(IEvent $event): array
    {
        if ($event instanceof ExternalPagesRequestEvent || $event instanceof ExternalComponentsRequestEvent) {
            $this->resources->register($event instanceof ExternalPagesRequestEvent ? $event->pageRegistry : $event->componentRegistry,
                $event instanceof ExternalComponentsRequestEvent, $this->diagnostic(...));
        } elseif ($event instanceof ExternalColorPalettesRequestEvent) {
            if (!$this->engine->hasActiveTheme()) return [];
            $theme = $this->engine->activeTheme();
            if (!$theme->enabled) return [];
            try {
                (new ThemeDocuments($this->engine))->selected($theme->packageId);
                $this->palettes->contribute($theme->packageId, $this->engine->definition()->label, $event->colorPaletteRegistry, $this->engine);
            } catch (\Throwable $error) { $this->diagnostic($error->getMessage()); }
        } elseif ($event instanceof PageDocumentRequestEvent) {
            if (class_exists(\Quill\services\editor\ExternalPageOverrides::class)
                && method_exists(\Quill\services\editor\ExternalPageOverrides::class, 'isComponent')
                && \Quill\services\editor\ExternalPageOverrides::isComponent($event->page)) {
                if (!$event->draft && $event->page->getParam('published_snapshot_id') !== null && !isset($this->handled[$event])) {
                    try {
                        $document = Documents::copy($event->getDocument()); StylePriority::inline($document);
                        $this->pageStylePriority($event, $document);
                        $event->setDocument($document, retainPageAssets: true); $this->handled[$event] = true;
                    } catch (\Throwable $error) { $this->diagnostic($error->getMessage()); }
                }
                return [];
            }
            if (!$event->draft && $event->page->getParam('published_snapshot_id') !== null) {
                $retained = $this->published->declaredAssets($event->page->getKey());
                foreach ($event->getDocument()->getSections() as $blocks) foreach ($blocks as $block) {
                    if (!$block->getBlockDescriptor() instanceof RuntimeDescriptor) $block->setBlockDescriptor(new RuntimeDescriptor($block->getBlockDescriptor(), $retained));
                }
            }
            if ($event->draft || !$this->engine->hasActiveTheme() || isset($this->handled[$event]) || isset($this->composed[$event->getDocument()])) return [];
            try {
                $theme = $this->engine->activeTheme();
                if ($event->page->getParam('published_snapshot_id') === null) return [];
                $document = (new ThemeDocuments($this->engine))->compose($event->getDocument(), $this->published->declaredAssets($event->page->getKey()));
                if ($document === null) return [];
                // LayoutBuilder leaves native wrappers unassigned until the normal URPC pass.
                (new \URPC\service\BlockIdentity())->assignDocument($document, $theme->name . '/quill-layout');
                $document = (new PublishedComponents($this->resources))->apply($document, (new ThemeDocuments($this->engine))->selected($theme->packageId), $this->diagnostic(...));
                $this->resources->themeStyles()->apply($document, $this->resources->themeStyles()->describe($theme->packageId));
                $this->pageStylePriority($event, $document);
                $event->setDocument($document, retainPageAssets: true); $this->handled[$event] = true; $this->composed[$document] = true;
            } catch (\Throwable $error) { $this->diagnostic($error->getMessage()); }
        } elseif ($event instanceof PageReadyEvent) {
            if (isset($this->handled[$event])) return [];
            $this->handled[$event] = true;
            try {
                $theme = $event->context->theme;
                if (!$theme->enabled) return [];
                // Loading also checks availability and validity of the actually rendered preview theme.
                (new ThemeDocuments($this->engine))->selected($theme->packageId);
                $document = (new PublishedComponents($this->resources))->apply($event->getDocument(),
                    (new ThemeDocuments($this->engine))->selected($theme->packageId), $this->diagnostic(...), $event->context->pageKey);
                $event->setDocument($document);
                $pageId = $this->links->find($theme->packageId, $event->context->pageKey);
                $sourceKey = Keys::source($theme->packageId, $event->context->pageKey);
                // Quill's explicit external Edit operation owns its source_key association.
                $external = class_exists(\Quill\services\editor\ExternalPageOverrides::class)
                    ? (new \Quill\services\editor\ExternalPageOverrides())->find($sourceKey) : null;
                if ($external !== null && $pageId !== null && $pageId !== $external->getKey()) throw new \LogicException('Ambiguous linked Quill pages for ' . $sourceKey);
                $pageId ??= $external?->getKey();
                if ($pageId === null) return [];
                $source = $external === null ? null : new \Quill\api\resources\ExternalDocument($sourceKey, $event->context->pageKey,
                    $this->engine->registry->get($theme->packageId)->name, $this->resources->page($theme->packageId, $event->context->pageKey));
                if (($published = $this->published->convert($pageId, $source)) === null) return [];
                [$document, $count] = (new Replacements())->apply($document, $published, $this->diagnostic(...), $theme->packageId, $event->context->pageKey);
                if ($count === 0) return [];
                (new RenderMetadata())->attach($document, $published);
                $event->setDocument($document);
            } catch (\Throwable $error) { $this->diagnostic($error->getMessage()); }
        }
        return [];
    }
}
