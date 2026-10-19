<?php
namespace QuillThemeBridge\services;

use event\interfaces\{IEvent, IEventListener};
use render\events\RenderReadyEvent;
use DOMXPath;

/** Augments the existing Quill views through AWT's public renderer.ready DOM hook. */
final class QuillViewExtension implements IEventListener
{
    public function __construct(private readonly Resources $resources) {}
    public function handle(IEvent $event): array
    {
        if (!$event instanceof RenderReadyEvent || ($event->renderer->packageName ?? '') !== 'Quill') return [];
        $renderer = $event->renderer; $dom = $renderer->getDom(); $xpath = new DOMXPath($dom);
        $bootstrapNode = $dom->getElementById('quill-editor-data'); $bootstrap = null;
        if ($bootstrapNode !== null) {
            $bootstrapValue = json_decode($bootstrapNode->textContent, false, 512, JSON_THROW_ON_ERROR);
            $bootstrap = json_decode($bootstrapNode->textContent, true, 512, JSON_THROW_ON_ERROR);
            // libxml saveHTML converts Unicode into HTML entities even in raw-text scripts.
            // JSON \u escapes survive that transport and JSON.parse restores the exact text.
            // Keep literal entity text intact: never HTML-decode document content.
            $json = json_encode($bootstrapValue, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $bootstrapNode->textContent = '';
            $bootstrapNode->appendChild($dom->createTextNode($json));
        }
        foreach (['externalPages', 'externalComponents'] as $field) {
            $entries = $renderer->bundle[$field] ?? [];
            $sectionId = $field === 'externalPages' ? 'quill-other-pages' : 'quill-external-components';
            $grids = $xpath->query('//section[@aria-labelledby="' . $sectionId . '"]/div[contains(@class,"card-grid")]');
            foreach ($grids as $grid) {
                $cards = iterator_to_array($xpath->query('./article', $grid)); $groups = [];
                // The original view renders this exact ordered bundle. Index associates DOM rows only, never resource identities.
                foreach ($cards as $index => $card) {
                    $entry = $entries[$index] ?? null; $key = $entry['id'] ?? null;
                    $meta = $key !== null ? ($this->resources->metadata[$key] ?? null) : null;
                    if ($meta === null || $card->hasAttribute('data-quill-theme-key')) continue;
                    $card->setAttribute('data-quill-theme-key', $key);
                    $provider = $xpath->query('.//*[contains(@class,"quill-provider")]', $card)->item(0);
                    if ($provider !== null) $provider->appendChild($dom->createTextNode(($meta['active_theme'] ? ' · Active theme' : '') . ' · ' . $meta['resource_type']));
                    $groups[$meta['group'] . ':' . $meta['group_key']]['metadata'] = $meta;
                    $groups[$meta['group'] . ':' . $meta['group_key']]['cards'][] = $card;
                }
                uasort($groups, static fn ($a, $b) => ($b['metadata']['active_theme'] <=> $a['metadata']['active_theme']) ?: strcmp($a['metadata']['group_key'], $b['metadata']['group_key']));
                $anchor = $grid->firstChild;
                foreach ($groups as $group) {
                    $heading = $dom->createElement('h3'); $heading->setAttribute('style', 'grid-column:1/-1');
                    $heading->appendChild($dom->createTextNode($group['metadata']['group'] . ' · ' . $group['metadata']['provider']));
                    $grid->insertBefore($heading, $anchor);
                    foreach ($group['cards'] as $card) $grid->insertBefore($card, $anchor);
                }
            }
        }
        if ($bootstrapNode !== null && $xpath->query('//script[@data-quill-theme-resources]')->length === 0) {
            $script = $dom->createElement('script'); $script->setAttribute('src', '/awt_packages/QuillThemeBridge/assets/quill-resources.js'); $script->setAttribute('defer', '');
            $script->setAttribute('data-quill-theme-resources', json_encode($this->resources->metadata, JSON_THROW_ON_ERROR));
            $styles = [];
            foreach ($this->resources->metadata as $key => $resource) if (isset($resource['theme_style'])) {
                $styles[$resource['themeKey'] . ':' . $resource['pageKey']] = $resource['theme_style'];
            }
            $visit = function (array $blocks) use (&$visit, &$styles): void {
                foreach ($blocks as $block) {
                    $reference = $block['descriptor']['props'][ThemeStyles::PROPERTY] ?? null;
                    if (is_array($reference) && is_int($reference['themeKey'] ?? null) && is_string($reference['pageKey'] ?? null)) {
                        try { $styles[$reference['themeKey'] . ':' . $reference['pageKey']] = $this->resources->themeStyles()->describe($reference['themeKey'], $reference['pageKey']); }
                        catch (\Throwable $error) { error_log('QuillThemeBridge editor styles: ' . $error->getMessage()); }
                    }
                    $visit($block['children'] ?? []);
                }
            };
            foreach ($bootstrap['document']['sections'] ?? [] as $section) $visit($section['blocks']);
            $script->setAttribute('data-quill-theme-styles', json_encode($styles, JSON_THROW_ON_ERROR));
            $head = $dom->getElementsByTagName('head')->item(0); $head?->appendChild($script);
        }
        return [];
    }
}
