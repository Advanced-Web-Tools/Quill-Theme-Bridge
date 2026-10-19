<?php
namespace QuillThemeBridge\services;

use QuillThemeBridge\api\{IThemePaletteProvider, ThemePalette};
use ThemeEngine\api\ThemePageContext;

/** Explicit adapters for the inspected StillTheme and MonoTheme CSS/settings contracts. */
final class KnownThemePalettes implements IThemePaletteProvider
{
    public function __construct(private readonly array $labels) {}
    public function palettes(ThemePageContext $context): array
    {
        [$base, $prefix, $variables] = match ($context->theme->name) {
            'StillTheme' => ['still-site', 'still-', ['paper', 'ink', 'muted', 'line']],
            'MonoTheme' => ['mono-site', 'mono-', ['mono-paper', 'mono-ink', 'mono-muted', 'mono-line']],
            default => throw new \InvalidArgumentException('No known palette adapter for this theme.'),
        };
        $options = ['paper', 'white', 'dark'];
        $path = \ThemeEngine\service\ThemeRegistry::resolveFile($context->theme->root, 'assets/theme.css');
        $css = file_get_contents($path);
        if ($css === false) throw new \UnexpectedValueException('Cannot read theme palette stylesheet.');
        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $rules = [];
        // These specific theme contracts use standalone class rules with literal custom-property values.
        preg_match_all('/(?:^|(?<=}))\s*(\.[a-zA-Z0-9_-]+)\s*\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (!in_array($match[1], ['.' . $base, '.' . $prefix . 'white', '.' . $prefix . 'dark'], true)) continue;
            if (isset($rules[$match[1]])) throw new \UnexpectedValueException('Ambiguous theme palette selector: ' . $match[1]);
            $rules[$match[1]] = $match[2];
        }
        $read = static function (string $selector) use ($rules): array {
            if (!isset($rules[$selector])) throw new \UnexpectedValueException('Missing theme palette selector: ' . $selector);
            $values = [];
            foreach (explode(';', $rules[$selector]) as $declaration) {
                if (preg_match('/^\s*--([a-zA-Z0-9_-]+)\s*:\s*(.+?)\s*$/sD', $declaration, $match)) $values[$match[1]] = $match[2];
            }
            return $values;
        };
        $defaults = $read('.' . $base);
        $labels = $this->labels;
        $palettes = [];
        foreach ($options as $key) {
            if (!is_string($labels[$key] ?? null)) throw new \UnexpectedValueException('Unsupported named theme palette: ' . $key);
            $values = $key === 'paper' ? $defaults : array_replace($defaults, $read('.' . $prefix . $key));
            $colors = [];
            foreach ($variables as $variable) {
                if (!isset($values[$variable])) throw new \UnexpectedValueException('Missing theme palette color: ' . $variable);
                $colors[] = ['id' => $variable, 'name' => ucfirst(str_replace('mono-', '', $variable)), 'value' => $values[$variable]];
            }
            $palettes[] = new ThemePalette($key, $labels[$key], $colors);
        }
        if (array_diff(array_keys($labels), $options)) throw new \UnexpectedValueException('Theme added an unsupported palette variant.');
        return $palettes;
    }
}
