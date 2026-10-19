<?php
namespace QuillThemeBridge\services;

use Quill\models\{QuillPage, QuillPageSnapshot};
use Quill\services\conversion\quillToUrpc\{ConversionResult, SnapshotQuillContentSource, QuillToUrpcConverter};

/** No renderer, page-document event or draft fallback. */
final class PublishedPageConversion
{
    /** Exact published asset declarations without conversion, rendering, or working-document reads. */
    public function declaredAssets(int $pageId): array
    {
        $page = (new QuillPage())->newQuery()->where(['id' => $pageId, 'deleted_at' => null])->firstModel();
        $id = $page?->getParam('published_snapshot_id');
        if ($id === null) return ['styles' => [], 'scripts' => []];
        $snapshot = (new QuillPageSnapshot())->useConnectionFrom($page)->newQuery()->where(['id' => $id, 'page_id' => $pageId])->firstModel();
        if ($snapshot === null) throw new \UnexpectedValueException('Missing exact published page snapshot.');
        $assets = $snapshot->getDocument()->assets ?? new \stdClass();
        return ['styles' => $assets->styles ?? [], 'scripts' => $assets->scripts ?? []];
    }
    public function convert(int $pageId, ?\Quill\api\resources\ExternalDocument $source = null, bool $publicScope = false): ?ConversionResult
    {
        $page = (new QuillPage())->newQuery()->where(['id' => $pageId, 'deleted_at' => null])->firstModel();
        $id = $page?->getParam('published_snapshot_id');
        if ($id === null) return null;
        $snapshot = (new QuillPageSnapshot())->useConnectionFrom($page)->newQuery()->where(['id' => $id, 'page_id' => $pageId])->firstModel();
        if ($snapshot === null) throw new \UnexpectedValueException('Missing exact published page snapshot.');
        if ($page->source_type === 'urpc') {
            $overrides = new \Quill\services\editor\ExternalPageOverrides();
            $kind = $overrides::isComponent($page) ? 'component' : 'page';
            if ($source === null || $page->source_key !== $overrides::resourceKey($source->key, $kind)) throw new \UnexpectedValueException('Exact external source definition is required.');
            $result = $overrides->render($source, $page, draft: false, kind: $kind);
            StylePriority::inline($result->document);
            return $result;
        }
        $content = $publicScope
            ? new \Quill\services\rendering\PublishedComponentContentSource(new SnapshotQuillContentSource($snapshot), $page)
            : new StrictPublishedContentSource(new SnapshotQuillContentSource($snapshot), $page);
        $result = (new QuillToUrpcConverter())->convert($content, 'Quill/page/' . $pageId);
        foreach (Documents::walk($result->document) as $block) {
            $metadata = $result->blockSources[spl_object_id($block)] ?? null;
            $identity = SourceIdentity::read($metadata);
            if ($identity !== null) {
                $block->getBlockDescriptor()->setBlockUid($identity['uid']);
                $block->getBlockDescriptor()->setSourceKey($identity['source'] ?: 'Quill/page/' . $pageId);
            }
        }
        StylePriority::inline($result->document);
        return $result;
    }
}
