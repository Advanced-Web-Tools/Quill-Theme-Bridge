<?php
namespace QuillThemeBridge\services;

/** Snapshot-owned descriptor metadata also survives Quill's ordinary external import. */
final class SourceIdentity
{
    public static function read(?\stdClass $block): ?array
    {
        if ($block === null) return null;
        $reference = $block->descriptor->props->{ThemeStyles::PROPERTY} ?? null;
        if (is_array($reference)) $reference = (object) $reference;
        $uid = $block->editor->urpcUid ?? $reference->uid ?? $block->editor->originalUrpcUid ?? null;
        $source = $reference->source ?? $block->editor->originalUrpcSource ?? '';
        if (!is_string($uid) || trim($uid) === '' || !is_string($source)) return null;
        return ['uid' => $uid, 'source' => $source, 'themeKey' => $reference->themeKey ?? null, 'pageKey' => $reference->pageKey ?? null];
    }
}
