<?php
namespace QuillThemeBridge\services;

use QuillThemeBridge\api\{IThemeStyleProvider, ThemeStyle};
use ThemeEngine\api\ThemePageContext;

/** Mirrors StillTheme's verified GalleryPage appearance contract without calling protected controller methods. */
final class StillThemeStyleProvider implements IThemeStyleProvider
{
    public function style(ThemePageContext $context): ThemeStyle
    {
        $palette = $context->settings['palette'] ?? null;
        $language = $context->settings['language'] ?? null;
        if (!in_array($palette, ['paper', 'white', 'dark'], true) || !in_array($language, ['en', 'sr'], true)) {
            throw new \UnexpectedValueException('Unsupported StillTheme palette/language contract.');
        }
        return new ThemeStyle(['still-site', 'still-' . $palette], [], $language);
    }
}
