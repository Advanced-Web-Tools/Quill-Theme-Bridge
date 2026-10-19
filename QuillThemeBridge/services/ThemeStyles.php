<?php
namespace QuillThemeBridge\services;

use ThemeEngine\service\Engine;
use ThemeEngine\api\ThemePageContext;
use context\Context;
use QuillThemeBridge\api\{IThemeStyleProvider, ThemeStyle};
use URPC\api\contracts\IURPCDocument;

/** Explicit mapping and live asset lookup, scoped by stable theme identity. */
final class ThemeStyles
{
    public const PROPERTY = 'quillThemeBridge';
    private array $providers = [];
    public function __construct(private readonly Engine $engine) {}
    public function register(int $themeId, IThemeStyleProvider $provider): void
    {
        if ($themeId < 1 || isset($this->providers[$themeId])) throw new \InvalidArgumentException('Invalid or duplicate theme style provider.');
        $this->providers[$themeId] = $provider;
    }
    public function describe(int $themeId, string $pageKey = ''): array
    {
        $engine = (new ThemeDocuments($this->engine))->selected($themeId); $theme = $engine->activeTheme();
        $context = new ThemePageContext(new Context($theme->root . '/', $theme->name, (string) $themeId), $theme, $pageKey, $engine->settings(), []);
        $provider = $this->providers[$themeId] ?? ($theme->name === 'StillTheme' ? new StillThemeStyleProvider() : null);
        try { $style = $provider?->style($context) ?? new ThemeStyle(); }
        catch (\Throwable $error) {
            error_log('QuillThemeBridge theme appearance ' . $themeId . ': ' . $error->getMessage());
            $style = new ThemeStyle();
        }
        return ['styles' => array_values(array_unique(['/awt_packages/ThemeEngine/assets/layout.css', ...$engine->definition()->stylesheets])),
            'scripts' => array_values(array_unique($engine->definition()->scripts)), 'bodyClasses' => array_values(array_unique(['te-page', ...$style->bodyClasses])),
            'variables' => $style->variables, 'language' => $style->language];
    }
    /** Source association survives Quill's documented descriptor props import/save/snapshot path. */
    public function mark(IURPCDocument $document, int $themeId, string $pageKey = ''): IURPCDocument
    {
        $document = Documents::identify($document, Keys::resource($themeId, 'source', $pageKey));
        foreach (Documents::walk($document) as $block) {
            $descriptor = $block->getBlockDescriptor(); $props = $descriptor->getBlockProps();
            if (isset($props[self::PROPERTY])) throw new \LogicException('Reserved theme metadata property already exists.');
            $props[self::PROPERTY] = (object) ['themeKey' => $themeId, 'pageKey' => $pageKey,
                'uid' => $descriptor->getBlockUid(), 'source' => $descriptor->getSourceKey()];
            $descriptor->setBlockProps($props);
        }
        return $document;
    }
    public function references(IURPCDocument $document): array
    {
        $references = [];
        foreach (Documents::walk($document) as $block) {
            $metadata = $block->getBlockDescriptor()->getBlockProps()[self::PROPERTY] ?? null;
            if (is_object($metadata)) $metadata = get_object_vars($metadata);
            if (!is_array($metadata) || !is_int($metadata['themeKey'] ?? null) || !is_string($metadata['pageKey'] ?? null)) continue;
            $references[$metadata['themeKey'] . ':' . $metadata['pageKey']] = ['themeKey' => $metadata['themeKey'], 'pageKey' => $metadata['pageKey']];
        }
        return array_values($references);
    }
    public function apply(IURPCDocument $document, array $appearance): void
    {
        foreach (Documents::walk($document) as $block) {
            $tag = strtolower($block->getBlockDescriptor()->getBlockName());
            if ($tag !== 'body' && $tag !== 'html') continue;
            $attributes = $block->getBlockAttributes();
            if ($tag === 'html' && $appearance['language'] !== null) $attributes['lang'] = $appearance['language'];
            if ($tag === 'body') {
                $classes = preg_split('/\s+/', trim($attributes['class'] ?? ''), flags: PREG_SPLIT_NO_EMPTY);
                $attributes['class'] = implode(' ', array_unique([...$classes, ...$appearance['bodyClasses']]));
                if ($appearance['variables'] !== []) {
                    $style = rtrim($attributes['style'] ?? '', '; ');
                    foreach ($appearance['variables'] as $key => $value) $style .= ($style === '' ? '' : ';') . $key . ':' . $value;
                    $attributes['style'] = $style;
                }
            }
            $block->setBlockAttributes($attributes);
        }
    }
}
