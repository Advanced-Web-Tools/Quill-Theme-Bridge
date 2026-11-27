<?php
namespace QuillThemeBridge\services;

use QuillThemeBridge\api\{IThemeStyleProvider, ThemeStyle};
use ThemeEngine\api\ThemePageContext;

final class TerraThemeStyleProvider implements IThemeStyleProvider
{
    public function style(ThemePageContext $context): ThemeStyle
    {
        $palette = $context->settings['palette'] ?? null;
        $language = $context->settings['language'] ?? null;
        if (!in_array($palette, ['wine', 'olive', 'ink'], true) || !in_array($language, ['en', 'sr'], true)) {
            throw new \UnexpectedValueException('Unsupported TerraTheme appearance settings.');
        }
        return new ThemeStyle(['terra-site', 'terra-' . $palette], [], $language);
    }
}
