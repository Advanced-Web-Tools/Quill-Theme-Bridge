<?php
namespace QuillThemeBridge\api;

use ThemeEngine\api\ThemePageContext;

/** Explicit appearance mapping; receives ThemeEngine's normalized settings for the selected theme. */
interface IThemeStyleProvider
{
    public function style(ThemePageContext $context): ThemeStyle;
}
