<?php

namespace QuillThemeBridge\services;

use event\interfaces\{IEvent, IEventListener};
use Quill\api\events\PageDocumentRequestEvent;
use Quill\controllers\PublicPageController;
use router\events\RouteEnterEvent;
use ThemeEngine\service\{LayoutBuilder, SiteAppearance};
use URPC\api\contracts\IURPCDocument;
use URPC\api\events\BeforeRenderEvent;
use URPC\api\URPCDocument;

final class QuillSiteAppearance implements IEventListener
{
    /** @var \WeakMap<PublicPageController, ?string> */
    private \WeakMap $publicPages;

    public function __construct(private readonly SiteAppearance $appearance)
    {
        $this->publicPages = new \WeakMap();
    }

    public function handle(IEvent $event): array
    {
        if ($event instanceof RouteEnterEvent && $event->controller instanceof PublicPageController && $event->action === 'index') {
            $this->publicPages[$event->controller] = null;
        } elseif ($event instanceof PageDocumentRequestEvent && !$event->draft) {
            foreach ($this->publicPages as $controller => $_) {
                if ($controller->page?->getKey() === $event->page->getKey()) {
                    $this->publicPages[$controller] = $event->page->name;
                }
            }
        } elseif ($event instanceof BeforeRenderEvent) {
            $name = $this->publicPages[$event->getURPC()] ?? null;
            if ($name !== null) {
                $this->publicPages[$event->getURPC()] = null;
                $this->apply($event->getDocument(), $name);
            }
        }
        return [];
    }

    private function apply(IURPCDocument $document, string $pageName): void
    {
        $sections = $document->getSections();
        $roots = array_merge(...array_map(array_values(...), array_values($sections)));
        $head = null;
        foreach ($roots as $root) {
            $tag = strtolower($root->getBlockDescriptor()->getBlockName());
            if ($tag === 'html') {
                $this->appearance->apply($document, $pageName);
                return;
            }
            if ($tag === 'head') {
                $head = $root;
                break;
            }
        }

        // ThemeEngine expects an html root. Give a Quill head or fragment a temporary wrapper.
        $fragment = $head === null;
        $head ??= LayoutBuilder::block('head', children: $roots);
        $metadata = new URPCDocument();
        $metadata->addBlock('main', LayoutBuilder::block('html', children: [$head]));
        $this->appearance->apply($metadata, $pageName);
        if (!$fragment) {
            return;
        }

        // Keep surviving fragment roots in their original sections and prepend new metadata.
        $updated = array_values($head->getBlockChildren());
        if ($updated === $roots) {
            return;
        }
        $originalIds = array_fill_keys(array_map(spl_object_id(...), $roots), true);
        $survivingIds = array_fill_keys(array_map(spl_object_id(...), $updated), true);
        foreach ($sections as $section => $blocks) {
            $sections[$section] = array_filter($blocks, static fn ($block) => isset($survivingIds[spl_object_id($block)]));
        }
        $added = array_filter($updated, static fn ($block) => !isset($originalIds[spl_object_id($block)]));
        $firstSection = array_key_first($sections) ?? 'main';
        $sections[$firstSection] = [...$added, ...array_values($sections[$firstSection] ?? [])];
        $document->fromArray($sections);
    }
}
