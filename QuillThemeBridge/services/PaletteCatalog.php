<?php
namespace QuillThemeBridge\services;

use Quill\api\resources\{ColorPaletteDefinition, ColorPaletteRegistry};
use QuillThemeBridge\api\{IThemePaletteProvider, ThemePalette};
use ThemeEngine\service\Engine;

/** Explicit theme adapters; no generic inference of palettes from settings or arbitrary CSS. */
final class PaletteCatalog
{
    private array $entries = [];
    private array $providers = [];
    public function registerProvider(int $themeId, IThemePaletteProvider $provider): void
    {
        if ($themeId < 1 || isset($this->providers[$themeId])) throw new \InvalidArgumentException('Invalid or duplicate theme palette provider.');
        $this->providers[$themeId] = $provider;
    }
    private function definition(int $themeId, ThemePalette $palette, string $provider): ColorPaletteDefinition
    {
        $key = Keys::resource($themeId, 'palette', $palette->key);
        $ids = [];
        $colors = array_map(static function ($color) use ($key, &$ids): array {
            if ($color instanceof \stdClass) $color = get_object_vars($color);
            if (!is_array($color) || !is_string($color['id'] ?? null) || trim($color['id']) === '' || isset($ids[$color['id']])) {
                throw new \InvalidArgumentException('Every palette color needs a unique stable source ID.');
            }
            $ids[$color['id']] = true;
            return array_replace($color, ['id' => 'qp-' . hash('sha256', $key . ':' . $color['id'])]);
        }, $palette->colors);
        return new ColorPaletteDefinition($key, $palette->name, $provider, $colors);
    }
    public function register(int $themeId, string $paletteKey, string $name, array $colors): void
    {
        $key = Keys::resource($themeId, 'palette', $paletteKey);
        if (isset($this->entries[$themeId][$key])) throw new \InvalidArgumentException('Duplicate palette identity.');
        $palette = new ThemePalette($paletteKey, $name, $colors);
        $this->definition($themeId, $palette, 'Theme'); // Validate before retaining contributions.
        $this->entries[$themeId][$key] = $palette;
    }
    public function contribute(int $themeId, string $provider, ColorPaletteRegistry $registry, ?Engine $engine = null): void
    {
        $definitions = []; $adapter = $this->providers[$themeId] ?? null;
        if ($engine !== null) {
            $engine = (new ThemeDocuments($engine))->selected($themeId); $theme = $engine->activeTheme();
            $provider = $engine->definition()->label;
            if ($adapter === null && in_array($theme->name, ['StillTheme', 'MonoTheme'], true)) {
                $adapter = new KnownThemePalettes($engine->definition()->settings['palette']['options'] ?? []);
            }
            if ($adapter !== null) {
                $context = new \ThemeEngine\api\ThemePageContext(new \context\Context($theme->root . '/', $theme->name, (string) $themeId), $theme, '', $engine->settings(), []);
                foreach ($adapter->palettes($context) as $palette) {
                    if (!$palette instanceof ThemePalette) throw new \UnexpectedValueException('Theme palette providers must return ThemePalette objects.');
                    $definition = $this->definition($themeId, $palette, $provider);
                    if (isset($definitions[$definition->key])) throw new \LogicException('Duplicate theme palette identity: ' . $definition->key);
                    $definitions[$definition->key] = $definition;
                }
            }
        }
        foreach ($this->entries[$themeId] ?? [] as $key => $palette) {
            if (isset($definitions[$key])) throw new \LogicException('Duplicate theme palette identity: ' . $key);
            $definitions[$key] = $this->definition($themeId, $palette, $provider);
        }
        foreach ($definitions as $definition) if ($registry->get($definition->key) !== null) throw new \LogicException('Duplicate external palette: ' . $definition->key);
        foreach ($definitions as $definition) $registry->add($definition);
    }
}
