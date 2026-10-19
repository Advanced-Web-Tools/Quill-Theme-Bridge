<?php
namespace QuillThemeBridge\services;

use Quill\services\conversion\quillToUrpc\QuillContentSource;

/** Lets Quill's own component expander scope actions/styles to a native theme placement. */
final class ComponentInstanceSource implements QuillContentSource
{
    public function __construct(private readonly QuillContentSource $component, private readonly string $instance) {}
    public function getScopeKey(): string { return 'QuillThemeBridge/instance/' . $this->instance; }
    public function getDocument(string $path): string
    {
        return json_encode(['schema_version' => 1, 'sections' => [['name' => 'main', 'blocks' => [1]]],
            'settings' => new \stdClass(), 'assets' => ['styles' => [], 'scripts' => []]], JSON_THROW_ON_ERROR);
    }
    public function getBlock(int $blockId, string $path): string
    {
        if ($blockId !== 1) throw new \OutOfBoundsException('Unknown component instance block.');
        return json_encode(['schema_version' => 1, 'kind' => 'component', 'component_id' => 1,
            'editor' => ['componentInstanceId' => 'qc-' . hash('sha256', $this->instance)]], JSON_THROW_ON_ERROR);
    }
    public function getComponent(int $componentId, string $path): QuillContentSource
    {
        if ($componentId !== 1) throw new \OutOfBoundsException('Unknown component instance.');
        return $this->component;
    }
}
