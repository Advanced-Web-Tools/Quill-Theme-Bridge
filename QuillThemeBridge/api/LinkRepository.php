<?php
namespace QuillThemeBridge\api;

interface LinkRepository
{
    public function find(int $themeKey, string $themePageKey): ?int;
    /** Insert only. A conflicting existing link must fail; replacement requires explicit unlink. */
    public function link(int $themeKey, string $themePageKey, int $quillPageId): void;
    public function unlink(int $themeKey, string $themePageKey, int $expectedQuillPageId): void;
}
