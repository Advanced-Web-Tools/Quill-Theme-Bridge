<?php
namespace QuillThemeBridge\services;

use database\DatabaseManager;
use Quill\models\{QuillComponent, QuillComponentSnapshot};
use Quill\services\conversion\quillToUrpc\{QuillContentSource, AnimationContentSource, SnapshotQuillContentSource};

/** Captured working component snapshots are insufficient: each component must be published. */
final class StrictPublishedContentSource implements QuillContentSource, AnimationContentSource
{
    public function __construct(private QuillContentSource $source, private DatabaseManager $database) {}
    public function getScopeKey(): string { return 'published/' . $this->source->getScopeKey(); }
    public function getDocument(string $path): string { return $this->source->getDocument($path); }
    public function getBlock(int $id, string $path): string { return $this->source->getBlock($id, $path); }
    public function getAnimationDefinition(int $id): ?\stdClass { return $this->source instanceof AnimationContentSource ? $this->source->getAnimationDefinition($id) : null; }
    public function getComponent(int $id, string $path): QuillContentSource
    {
        $this->source->getComponent($id, $path); // Check the owning manifest even for independently published components.
        $component = (new QuillComponent())->useConnectionFrom($this->database)->newQuery()->where(['id' => $id, 'deleted_at' => null])->firstModel();
        $snapshotId = $component?->getParam('published_snapshot_id');
        if ($snapshotId === null) throw new \UnexpectedValueException('Replacement contains an unpublished component.');
        $snapshot = (new QuillComponentSnapshot())->useConnectionFrom($this->database)->newQuery()->where(['id' => $snapshotId, 'component_id' => $id])->firstModel();
        if ($snapshot === null) throw new \UnexpectedValueException('Missing exact published component snapshot.');
        return new self(new SnapshotQuillContentSource($snapshot), $this->database);
    }
}
