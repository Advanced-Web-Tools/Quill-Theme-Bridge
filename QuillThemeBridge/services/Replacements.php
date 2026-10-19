<?php
namespace QuillThemeBridge\services;

use Quill\services\conversion\quillToUrpc\ConversionResult;
use URPC\api\contracts\{IURPCDocument, IURPCBlock};

final class Replacements
{
    /** Return [isolated document, number of replaced roots]. Identity comes from snapshot metadata. */
    public function apply(IURPCDocument $original, ConversionResult $published, callable $diagnostic, ?int $themeKey = null, ?string $pageKey = null): array
    {
        $index = []; $counts = []; $targetCounts = [];
        foreach (Documents::walk($original) as $block) {
            $uid = $block->getBlockDescriptor()->getBlockUid();
            if ($uid !== '') $targetCounts[$uid] = ($targetCounts[$uid] ?? 0) + 1;
        }
        foreach (Documents::walk($published->document) as $block) {
            $source = $published->blockSources[spl_object_id($block)] ?? null;
            $identity = SourceIdentity::read($source);
            if ($identity === null) continue;
            if ($themeKey !== null && $identity['themeKey'] !== null && ($identity['themeKey'] !== $themeKey || $identity['pageKey'] !== $pageKey)) continue;
            $uid = $identity['uid'];
            $counts[$uid] = ($counts[$uid] ?? 0) + 1; $index[$uid] = $block;
        }
        foreach ($counts as $uid => $count) if ($count !== 1 || ($targetCounts[$uid] ?? 0) > 1) {
            unset($index[$uid]); $diagnostic('Ambiguous URPC UID: ' . $uid);
        }
        // A replacement parent must not smuggle ambiguous identities in its complete subtree.
        foreach ($index as $uid => $root) {
            $check = function (IURPCBlock $block) use (&$check, $published, $counts, $targetCounts): bool {
                $identity = SourceIdentity::read($published->blockSources[spl_object_id($block)] ?? null)['uid'] ?? null;
                if (is_string($identity) && (($counts[$identity] ?? 0) > 1 || ($targetCounts[$identity] ?? 0) > 1)) return false;
                foreach ($block->getBlockChildren() as $child) if (!$check($child)) return false;
                return true;
            };
            if (!$check($root)) unset($index[$uid]);
        }
        $document = Documents::copy($original); $replaced = 0;
        $visit = function (array $blocks) use (&$visit, $index, &$replaced): array {
            $result = [];
            foreach ($blocks as $block) {
                $uid = $block->getBlockDescriptor()->getBlockUid();
                if (isset($index[$uid])) {
                    $replacement = Documents::block($index[$uid]);
                    $replacement->getBlockDescriptor()->setBlockUid($uid);
                    $replacement->getBlockDescriptor()->setSourceKey($block->getBlockDescriptor()->getSourceKey());
                    $result[] = $replacement; $replaced++;
                    // This subtree is complete. Never walk the original parent's children again.
                } else {
                    $block->setBlockChildren($visit($block->getBlockChildren())); $result[] = $block;
                }
            }
            return $result;
        };
        $sections = [];
        foreach ($document->getSections() as $name => $blocks) $sections[$name] = $visit($blocks);
        $document->fromArray($sections);
        return [$document, $replaced];
    }
}
