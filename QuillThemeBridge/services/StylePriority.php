<?php
namespace QuillThemeBridge\services;

use URPC\api\contracts\IURPCDocument;

/** Priority for Quill declarations only; external theme stylesheets remain untouched. */
final class StylePriority
{
    public static function declarations(string $declarations): string
    {
        // Quill validates generated declaration values and rejects embedded semicolons/braces.
        return preg_replace_callback('/(^|;)(\s*(?:--[A-Za-z_][A-Za-z0-9_-]*|-?[A-Za-z][A-Za-z0-9-]*)\s*:\s*)([^;]+)(?=;|$)/',
            static fn ($match) => $match[1] . $match[2] . (preg_match('/!\s*important\s*$/iD', $match[3]) ? $match[3] : rtrim($match[3]) . '!important'), $declarations);
    }
    public static function css(string $css): string
    {
        // Match only generated block/page rules, including rules inside media queries.
        // Keyframes and animation runtime declarations deliberately keep their normal cascade.
        return preg_replace_callback('/(^|(?<=[{}]))(\s*(?:\.quill-scope-[a-f0-9]{64}(?::[a-z-]+|\[hidden\])?|body)\s*)\{([^{}]*)\}/m',
            static fn ($match) => $match[1] . $match[2] . '{' . self::declarations($match[3]) . '}', $css);
    }
    public static function inline(IURPCDocument $document): void
    {
        foreach (Documents::walk($document) as $block) {
            $attributes = $block->getBlockAttributes();
            if (!is_string($attributes['style'] ?? null) || $attributes['style'] === '') continue;
            // Inline values may contain quoted semicolons or URL data: use a declaration
            // scanner rather than splitting them into arbitrary CSS fragments.
            $style = $attributes['style']; $parts = []; $start = 0; $depth = 0; $quote = null;
            for ($index = 0, $length = strlen($style); $index < $length; $index++) {
                $char = $style[$index];
                if ($char === '\\') { $index++; continue; }
                if ($quote !== null) { if ($char === $quote) $quote = null; continue; }
                if ($char === '/' && ($style[$index + 1] ?? '') === '*') {
                    $end = strpos($style, '*/', $index + 2);
                    if ($end === false) { $quote = 'unterminated-comment'; break; }
                    $index = $end + 1; continue;
                }
                if ($char === '"' || $char === "'") { $quote = $char; continue; }
                if ($char === '(') $depth++;
                if ($char === ')') $depth--;
                if ($char === ';' && $depth === 0) { $parts[] = substr($style, $start, $index - $start); $start = $index + 1; }
            }
            if ($quote !== null || $depth !== 0) continue;
            $parts[] = substr($style, $start);
            foreach ($parts as &$part) {
                if (preg_match('/^\s*(?:--[A-Za-z_][A-Za-z0-9_-]*|-?[A-Za-z][A-Za-z0-9-]*)\s*:\s*\S/s', $part)
                    && !preg_match('/!\s*important\s*$/iD', preg_replace('~/\*.*?\*/~s', '', $part))) $part = rtrim($part) . '!important';
            }
            unset($part);
            $attributes['style'] = implode(';', $parts); $block->setBlockAttributes($attributes);
        }
    }
}
