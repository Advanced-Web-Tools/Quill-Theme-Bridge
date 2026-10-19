<?php
use runtime\api\RuntimeAPI;
use ThemeEngine\service\Engine;
use QuillThemeBridge\services\{DatabaseLinks, Resources, LinkedEditing, BridgeListener};
use Quill\api\events\{ExternalPagesRequestEvent, ExternalComponentsRequestEvent, ExternalColorPalettesRequestEvent, PageDocumentRequestEvent};
use ThemeEngine\api\events\PageReadyEvent;

final class QuillThemeBridge extends RuntimeAPI
{
    public function environmentSetup(): void
    {
        parent::environmentSetup();
        foreach (['URPC', 'ThemeEngine', 'Quill'] as $package) $this->waitForPackage($package);
    }
    public function main(): void
    {
        $engine = $this->getShared('ThemeEngine', 'engine', Engine::class) ?? throw new \LogicException('Missing ThemeEngine engine.');
        $context = new \context\Context(__DIR__, 'QuillThemeBridge', (string) $this->getInfo()['id']);
        $links = new DatabaseLinks(new \database\provider\DatabaseProvider());
        $styles = new \QuillThemeBridge\services\ThemeStyles($engine);
        $resources = new Resources($engine, $context, $styles);
        $this->setShared('themeStyles', $styles);
        $this->eventDispatcher->addListener(\URPC\api\events\BeforeRenderEvent::NAME, new \QuillThemeBridge\services\ThemeAssetsListener($styles));
        $palettes = new \QuillThemeBridge\services\PaletteCatalog();
        $listener = new BridgeListener($engine, $context, $links, $resources, palettes: $palettes);
        $this->eventDispatcher->addListener('renderer.ready', new \QuillThemeBridge\services\QuillViewExtension($resources));
        $this->setShared('palettes', $palettes);
        foreach ([ExternalPagesRequestEvent::NAME, ExternalComponentsRequestEvent::NAME, ExternalColorPalettesRequestEvent::NAME,
            PageDocumentRequestEvent::NAME, PageReadyEvent::NAME] as $event) $this->eventDispatcher->addListener($event, $listener);
        $this->setShared('linkedEditing', new LinkedEditing($resources, $links));
    }
}
