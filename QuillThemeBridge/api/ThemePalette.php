<?php
namespace QuillThemeBridge\api;

/** Stable source identity, readable label and Quill-supported named paints. */
final class ThemePalette
{
    public function __construct(public readonly string $key, public readonly string $name, public readonly array $colors)
    {
        if (trim($key) === '') throw new \InvalidArgumentException('Palette identity is required.');
    }
}
