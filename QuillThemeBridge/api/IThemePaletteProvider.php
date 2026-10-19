<?php
namespace QuillThemeBridge\api;

use ThemeEngine\api\ThemePageContext;

interface IThemePaletteProvider
{
    /** @return list<ThemePalette> Read-only discovery; do not render pages or write settings. */
    public function palettes(ThemePageContext $context): array;
}
