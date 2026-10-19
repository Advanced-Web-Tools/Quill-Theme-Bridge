<?php
namespace QuillThemeBridge\services;

use URPC\api\contracts\{IURPCDocument, IURPCBlock};

final class Documents
{
    /** Assign missing identities on an isolated tree; never replace an existing source UID. */
    public static function identify(IURPCDocument $document, string $namespace): IURPCDocument
    {
        $document = self::copy($document);
        $generated = self::copy($document);
        (new \URPC\service\BlockIdentity())->assignDocument($generated, $namespace);
        $identities = self::walk($generated); $seen = [];
        foreach (self::walk($document) as $index => $block) {
            $descriptor = $block->getBlockDescriptor();
            if ($descriptor->getBlockUid() === '') $descriptor->setBlockUid($identities[$index]->getBlockDescriptor()->getBlockUid());
            if ($descriptor->getSourceKey() === '') $descriptor->setSourceKey($identities[$index]->getBlockDescriptor()->getSourceKey());
            $uid = $descriptor->getBlockUid();
            if (isset($seen[$uid])) throw new \LogicException('Ambiguous source URPC UID: ' . $uid);
            $seen[$uid] = true;
        }
        return $document;
    }
    public static function copy(IURPCDocument $document): IURPCDocument
    {
        $copy = clone $document; $sections = [];
        foreach ($document->getSections() as $name => $blocks) $sections[$name] = array_map(self::block(...), $blocks);
        $copy->fromArray($sections); return $copy;
    }
    public static function block(IURPCBlock $block, array $active = []): IURPCBlock
    {
        $id = spl_object_id($block);
        if (isset($active[$id]) || count($active) >= 128) throw new \LogicException('Cyclic or too deep source tree.');
        $active[$id] = true; $copy = clone $block;
        $copy->setBlockDescriptor(clone $block->getBlockDescriptor());
        $copy->setBlockChildren(array_map(static fn ($child) => self::block($child, $active), $block->getBlockChildren()));
        return $copy;
    }
    public static function walk(IURPCDocument $document): array
    {
        $all = []; $active = [];
        $visit = function (IURPCBlock $block, int $depth = 0) use (&$visit, &$all, &$active): void {
            $id = spl_object_id($block);
            if (isset($active[$id]) || $depth >= 128) throw new \LogicException('Cyclic or too deep source tree.');
            $active[$id] = true; $all[] = $block;
            foreach ($block->getBlockChildren() as $child) $visit($child, $depth + 1);
            unset($active[$id]);
        };
        foreach ($document->getSections() as $blocks) foreach ($blocks as $block) $visit($block);
        return $all;
    }
}
