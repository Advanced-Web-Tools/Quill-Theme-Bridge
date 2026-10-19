<?php
namespace QuillThemeBridge\services;

final class Keys
{
    /** Package ID is ThemeEngine's persisted identity, independent of package label/name and routes. */
    public static function resource(int $theme, string $kind, string $key): string
    {
        if ($theme < 1) throw new \InvalidArgumentException('Invalid theme identity.');
        return 'QuillThemeBridge:t' . $theme . ':' . $kind . ':' . bin2hex($key);
    }
    public static function source(int $theme, string $page): string { return self::resource($theme, 'page', $page); }
}
