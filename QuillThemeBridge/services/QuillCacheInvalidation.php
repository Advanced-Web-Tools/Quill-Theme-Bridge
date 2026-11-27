<?php
namespace QuillThemeBridge\services;

use event\EventDispatcher;
use event\interfaces\{IEvent, IEventListener};
use Quill\controllers\QuillSaveController;
use Quill\models\QuillPage;
use router\events\RouteEnterEvent;
use router\http\Request;
use router\matching\SegmentPathMatcher;
use ThemeEngine\service\Engine;
use URPC\api\events\InvalidateTemplateCacheEvent;

final class QuillCacheInvalidation implements IEventListener
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly Engine $engine,
        private readonly ThemeRecovery $recovery,
    ) {}

    public function handle(IEvent $event): array
    {
        if (!$event instanceof RouteEnterEvent || !$event->controller instanceof QuillSaveController) return [];
        $request = new Request($_SERVER, $_POST);
        if ($request->method() !== 'POST') return [];

        $params = (new SegmentPathMatcher())->match($event->path, $request->path()) ?? [];
        $id = match ($event->action) {
            'saveDocument' => ($params['type'] ?? null) === 'page' ? ($params['id'] ?? null) : null,
            'restorePageSnapshot', 'deletePageSnapshot', 'savePageTemplate' => $params['id'] ?? null,
            'updatePage', 'deletePage' => $_POST['id'] ?? null,
            default => null,
        };
        $pageId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        // Deleting an external owner clears its source key before the response.
        $deletedSource = null;
        if ($event->action === 'deletePage' && $pageId !== null) {
            try { $deletedSource = $this->page($pageId)?->source_key; }
            catch (\Throwable $error) { $this->report($error); }
        }

        // Quill has no save event. At request completion the controller has already
        // committed successful saves, and validation and authorization have a status.
        register_shutdown_function(function () use ($pageId, $deletedSource): void {
            $status = http_response_code();
            if ($status < 200 || $status >= 300) return;
            try { $this->invalidate($pageId, $deletedSource); }
            catch (\Throwable $error) { $this->report($error); }
        });
        return [];
    }

    private function page(int $id): ?QuillPage
    {
        return (new QuillPage())->newQuery()->where(['id' => $id])->firstModel();
    }

    private function invalidate(?int $pageId, ?string $deletedSource): void
    {
        $namespaces = ['Quill' => true];
        $sourceKey = $deletedSource ?? ($pageId === null ? null : $this->page($pageId)?->source_key);
        if ($pageId !== null) $namespaces['Quill/page/' . $pageId] = true;
        if ($sourceKey !== null && $sourceKey !== '') $namespaces['Quill/external/' . $sourceKey] = true;

        // Shared components and libraries can affect any saved Quill page.
        if ($pageId === null || str_starts_with((string) $sourceKey, 'Quill/external/component/')) {
            foreach ((new QuillPage())->newQuery()->where(['deleted_at' => null])->getModels() as $page) {
                $namespaces['Quill/page/' . $page->getKey()] = true;
                if ($page->source_key) $namespaces['Quill/external/' . $page->source_key] = true;
            }
        }

        $themeId = null;
        $pageKey = null;
        if (is_string($sourceKey) && preg_match('/^QuillThemeBridge:t([1-9][0-9]*):page:((?:[0-9a-f]{2})+)$/D', $sourceKey, $matches)) {
            $themeId = $this->recovery->installedThemeId((int) $matches[1]);
            $pageKey = hex2bin($matches[2]);
        }
        foreach ($this->engine->registry->all() as $theme) {
            if (!$theme->enabled || ($themeId !== null && $theme->packageId !== $themeId)) continue;
            try {
                foreach ($this->engine->registry->load($theme->packageId)->pages as $page) {
                    if ($pageKey === null || $pageKey === $page->key) {
                        $namespaces[$theme->name . '/pages/' . $page->key] = true;
                    }
                }
            } catch (\Throwable $error) { $this->report($error); }
        }

        foreach (array_keys($namespaces) as $namespace) {
            try { $this->dispatcher->dispatch(new InvalidateTemplateCacheEvent($namespace)); }
            catch (\Throwable $error) { $this->report($error); }
        }
    }

    private function report(\Throwable $error): void
    {
        error_log('QuillThemeBridge template cache: ' . $error->getMessage());
    }
}
