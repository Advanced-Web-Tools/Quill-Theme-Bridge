<?php
namespace QuillThemeBridge\services;

use QuillThemeBridge\api\{IThemeStyleProvider, ThemeStyle};
use ThemeEngine\api\ThemePageContext;

final class MonoThemeStyleProvider implements IThemeStyleProvider
{
    public function style(ThemePageContext $context): ThemeStyle
    {
        $palette = $context->settings['palette'] ?? null;
        $spacing = $context->settings['spacing'] ?? null;
        $language = $context->settings['language'] ?? null;
        if (!in_array($palette, ['paper', 'white', 'dark'], true)
            || !in_array($spacing, ['editorial', 'compact'], true)
            || !in_array($language, ['en', 'sr'], true)) {
            throw new \UnexpectedValueException('Unsupported MonoTheme appearance settings.');
        }
        return new ThemeStyle(['mono-site', 'mono-' . $palette, 'mono-density-' . $spacing], [], $language);
    }
}
