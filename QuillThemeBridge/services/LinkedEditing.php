<?php
namespace QuillThemeBridge\services;

use admin\Admin;
use router\security\Csrf;
use Quill\models\QuillPage;
use Quill\services\editor\{EditorDocumentSaver, EditorContentSource, EditorDocumentLoader};
use Quill\api\resources\ExternalDocument;
use QuillThemeBridge\api\LinkRepository;

/** Explicit service API, without a bridge route, screen or alternate save pipeline. */
final class LinkedEditing
{
    public function __construct(private readonly Resources $resources, private readonly LinkRepository $links) {}
    private function authorize(string $csrfToken): int
    {
        $admin = new Admin();
        if (!$admin->checkAuthentication() || !$admin->checkPermission(1) || !(new Csrf())->validateToken($csrfToken)) {
            throw new \DomainException('Authenticated Quill permission and a valid CSRF token are required.');
        }
        return $admin->getKey();
    }
    public function create(int $themeKey, string $pageKey, string $name, string $csrfToken): QuillPage
    {
        $adminId = $this->authorize($csrfToken);
        $this->assertNoExternalLink($themeKey, $pageKey);
        if ($this->links->find($themeKey, $pageKey) !== null) throw new \DomainException('Source already has an explicit link.');
        if (trim($name) === '') throw new \InvalidArgumentException('Page name is required.');
        $source = $this->resources->page($themeKey, $pageKey);
        $import = (new \Quill\services\conversion\urpcToQuill\UrpcToQuillConverter())->convert($source);
        $blocks = $import->blocks;
        foreach ($import->identities as $id => $identity) {
            if ($identity['uid'] === '') continue;
            $blocks[$id]->editor->originalUrpcUid = $identity['uid'];
            $blocks[$id]->editor->originalUrpcSource = $identity['source_key'];
        }
        $converted = (new \Quill\services\conversion\quillToUrpc\QuillToUrpcConverter())->convert(new EditorContentSource($import->document, $blocks));
        $document = (new EditorDocumentLoader())->expanded($converted);
        $document->settings = (object) ['title' => $name];
        $document->assets = $import->document->assets;
        $page = new QuillPage();
        return $page->transaction(function () use ($page, $themeKey, $pageKey, $name, $adminId, $document) {
            $page->name = $name; $page->admin_id = $adminId; $page->source_type = 'quill'; $page->status = 'draft'; $page->lock_version = 1;
            $page->setDocument(new \stdClass());
            if ($page->saveModel() === null) throw new \RuntimeException('Cannot create linked draft.');
            (new EditorDocumentSaver())->save($page, $document, 1, 'draft', $adminId);
            $this->links->link($themeKey, $pageKey, $page->getKey());
            $page->selectByID($page->getKey()); return $page;
        });
    }
    public function link(int $themeKey, string $pageKey, int $quillPageId, string $csrfToken): void
    {
        $this->authorize($csrfToken); $this->resources->page($themeKey, $pageKey);
        $this->assertNoExternalLink($themeKey, $pageKey);
        $page = (new QuillPage())->newQuery()->where(['id' => $quillPageId, 'deleted_at' => null])->firstModel();
        if ($page === null || $page->source_type !== 'quill') throw new \InvalidArgumentException('A local Quill page is required.');
        $this->links->link($themeKey, $pageKey, $quillPageId);
    }
    private function assertNoExternalLink(int $themeKey, string $pageKey): void
    {
        if (class_exists(\Quill\services\editor\ExternalPageOverrides::class)
            && (new \Quill\services\editor\ExternalPageOverrides())->find(Keys::source($themeKey, $pageKey)) !== null) {
            throw new \DomainException('Source already has an explicit Quill external editor link.');
        }
    }
    public function unlink(int $themeKey, string $pageKey, int $expectedQuillPageId, string $csrfToken): void
    {
        $this->authorize($csrfToken); $this->links->unlink($themeKey, $pageKey, $expectedQuillPageId);
    }
}
