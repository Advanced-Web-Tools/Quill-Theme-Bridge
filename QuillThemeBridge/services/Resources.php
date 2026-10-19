<?php
namespace QuillThemeBridge\services;

use context\Context;
use ThemeEngine\service\Engine;
use ThemeEngine\api\ThemePageContext;
use Quill\api\resources\{ExternalDocument, DocumentRegistry};

final class Resources
{
    private readonly ThemeStyles $styles;
    public function __construct(private readonly Engine $engine, private readonly Context $context, ?ThemeStyles $styles = null) { $this->styles = $styles ?? new ThemeStyles($engine); }
    public function themeStyles(): ThemeStyles { return $this->styles; }
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
            $add = function (string $type, string $key, string $name, callable $document, ?string $url = null) use ($theme, $definition, $active, $registry, $diagnostic): void {
                try {
                    $pageKey = $type === 'page' ? $key : '';
                    $key = Keys::resource($theme->packageId, $type, $key);
                    $registry->add(new ExternalDocument($key, $name, $definition->label, ($type === 'page' ? $document() : $this->styles->mark($document(), $theme->packageId, $pageKey)), $theme->description ?? '', $url));
                    $this->metadata[$key] = ['group' => $active ? 'Active theme' : 'Other themes', 'group_key' => 'theme:' . $theme->packageId,
                        'themeKey' => $theme->packageId, 'pageKey' => $pageKey, 'provider' => $definition->label, 'resource_type' => match ($type) { 'part' => 'Layout part', 'widget' => 'Widget', default => 'Page' }, 'active_theme' => $active, 'theme_style' => $this->styles->describe($theme->packageId, $pageKey)];
                } catch (\Throwable $error) { $diagnostic('Theme ' . $theme->packageId . ' ' . $type . ' ' . $key . ': ' . $error->getMessage()); }
            };
            if (!$components) {
                foreach ($definition->pages as $page) {
                    $url = null;
                    foreach ($definition->routes as $route) if ($route->pageKey === $page->key) { $url = $route->path; break; }
                    $add('page', $page->key, $page->label, fn () => $this->page($theme->packageId, $page->key), $url);
                }
            } else {
                foreach ($definition->sections as $slot => $source) $add('part', $slot, ucfirst($slot), fn () => (new ThemeDocuments($this->engine))->part($theme->packageId, $slot));
                foreach ($selected->widgets->all() as $widget) $add('widget', $widget->key, $widget->label, fn () => (new ThemeDocuments($this->engine))->widget($theme->packageId, $widget->key));
            }
        }
    }
    public function page(int $themeKey, string $pageKey): \URPC\api\contracts\IURPCDocument
    {
        return $this->styles->mark((new ThemeDocuments($this->engine))->page($themeKey, $pageKey), $themeKey, $pageKey);
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
        return new ExternalDocument(Keys::resource($themeKey, $type, $key), $type === 'part' ? ucfirst($key) : $engine->widgets->all()[$key]->label,
            $engine->definition()->label, $this->styles->mark($document, $themeKey), $engine->activeTheme()->description ?? '');
    }
}
