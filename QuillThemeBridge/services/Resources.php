<?php
namespace QuillThemeBridge\services;

use context\Context;
use ThemeEngine\service\Engine;
use Quill\api\resources\{ExternalDocument, DocumentRegistry};

final class Resources
{
    private readonly ThemeStyles $styles;
    public function __construct(private readonly Engine $engine, private readonly Context $context, ?ThemeStyles $styles = null,
        private readonly ?ThemeRecovery $recovery = null) { $this->styles = $styles ?? new ThemeStyles($engine, $recovery); }
    public function themeStyles(): ThemeStyles { return $this->styles; }
    public function sourceThemeId(int $themeId, string $type, string $key): int
    {
        return $this->recovery?->resourceThemeId($themeId, $type, $key) ?? $themeId;
    }
    public function resourceKey(int $themeId, string $type, string $key): string
    {
        return Keys::resource($this->sourceThemeId($themeId, $type, $key), $type, $key);
    }
    public array $metadata = [];
    public function register(DocumentRegistry $registry, bool $components, callable $diagnostic): void
    {
        $themes = $this->engine->registry->all();
        $activeId = $this->engine->hasActiveTheme() ? $this->engine->activeTheme()->packageId : null;
        uasort($themes, static fn ($a, $b) => ($b->packageId === $activeId) <=> ($a->packageId === $activeId) ?: ($a->packageId <=> $b->packageId));
        foreach ($themes as $theme) {
            if (!$theme->enabled) continue;
            try { $selected = (new ThemeDocuments($this->engine))->selected($theme->packageId); $definition = $selected->definition(); }
            catch (\Throwable $error) { $diagnostic('Theme ' . $theme->packageId . ': ' . $error->getMessage()); continue; }
            $active = $this->engine->hasActiveTheme() && $this->engine->activeTheme()->packageId === $theme->packageId;
            $add = function (string $type, string $key, callable $load) use ($theme, $definition, $active, $registry, $diagnostic): void {
                try {
                    $pageKey = $type === 'page' ? $key : '';
                    $resource = $load();
                    $registry->add($resource);
                    $this->metadata[$resource->key] = ['group' => $active ? 'Active theme' : 'Other themes', 'group_key' => 'theme:' . $theme->packageId,
                        'themeKey' => $theme->packageId, 'pageKey' => $pageKey, 'provider' => $definition->label, 'resource_type' => match ($type) { 'part' => 'Layout part', 'widget' => 'Widget', default => 'Page' }, 'active_theme' => $active, 'theme_style' => $this->styles->describe($theme->packageId, $pageKey)];
                } catch (\Throwable $error) { $diagnostic('Theme ' . $theme->packageId . ' ' . $type . ' ' . $key . ': ' . $error->getMessage()); }
            };
            if (!$components) {
                foreach ($definition->pages as $page) {
                    $url = null;
                    foreach ($definition->routes as $route) if ($route->pageKey === $page->key) { $url = $route->path; break; }
                    $add('page', $page->key, fn () => new ExternalDocument($this->resourceKey($theme->packageId, 'page', $page->key),
                        $page->label, $definition->label, $this->page($theme->packageId, $page->key), $theme->description ?? '', $url));
                }
            } else {
                foreach (array_keys($definition->sections) as $slot) $add('part', $slot, fn () => $this->component($theme->packageId, 'part', $slot));
                foreach ($selected->widgets->all() as $widget) $add('widget', $widget->key, fn () => $this->component($theme->packageId, 'widget', $widget->key));
            }
        }
    }
    public function page(int $themeKey, string $pageKey): \URPC\api\contracts\IURPCDocument
    {
        return $this->styles->mark((new ThemeDocuments($this->engine))->page($themeKey, $pageKey), $this->sourceThemeId($themeKey, 'page', $pageKey), $pageKey);
    }
    /** Original provider document only: discovery must never resolve its own saved overrides. */
    public function component(int $themeKey, string $type, string $key): ExternalDocument
    {
        $documents = new ThemeDocuments($this->engine); $engine = $documents->selected($themeKey);
        $document = match ($type) {
            'part' => $documents->part($themeKey, $key),
            'widget' => $documents->widget($themeKey, $key),
            default => throw new \InvalidArgumentException('Unknown theme component type.'),
        };
        $sourceThemeId = $this->sourceThemeId($themeKey, $type, $key);
        return new ExternalDocument(Keys::resource($sourceThemeId, $type, $key), $type === 'part' ? ucfirst($key) : $engine->widgets->all()[$key]->label,
            $engine->definition()->label, $this->styles->mark($document, $sourceThemeId), $engine->activeTheme()->description ?? '');
    }
}
