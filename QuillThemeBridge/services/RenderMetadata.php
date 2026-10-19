<?php
namespace QuillThemeBridge\services;

use Quill\services\conversion\quillToUrpc\ConversionResult;
use ThemeEngine\service\LayoutBuilder;
use URPC\api\contracts\IURPCDocument;
use URPC\service\BlockIdentity;

/** Adds ordinary URPC asset nodes; URPC remains the sole renderer. */
final class RenderMetadata
{
    public function attach(IURPCDocument $document, ConversionResult $result): void
    {
        $head = null; $body = null; $seen = [];
        foreach (Documents::walk($document) as $block) {
            $tag = strtolower($block->getBlockDescriptor()->getBlockName()); $attrs = $block->getBlockAttributes();
            if ($tag === 'head') $head ??= $block;
            if ($tag === 'body') $body ??= $block;
            if ($tag === 'link' && ($attrs['rel'] ?? '') === 'stylesheet') $seen['style:' . ($attrs['href'] ?? '')] = true;
            if ($tag === 'script') $seen['script:' . ($attrs['src'] ?? '')] = true;
            $descriptor = $block->getBlockDescriptor();
            if ($descriptor instanceof RuntimeDescriptor) {
                foreach ($descriptor->retainedAssets['styles'] as $url) $seen['style:' . $url] = true;
                foreach ($descriptor->retainedAssets['scripts'] as $url) $seen['script:' . $url] = true;
            }
        }
        $headBlocks = []; $bodyBlocks = [];
        $styles = $result->assets['styles'];
        if ($result->scopedCss !== '') $styles[] = 'data:text/css;base64,' . base64_encode(StylePriority::css($result->scopedCss));
        foreach (array_unique($styles) as $url) if (!isset($seen['style:' . $url])) $headBlocks[] = LayoutBuilder::block('link', '', ['rel' => 'stylesheet', 'href' => $url]);
        $scripts = $result->assets['scripts'];
        if ($result->actions !== []) {
            $config = json_encode(['actions' => $result->actions, 'breakpoints' => (object) $result->actionBreakpoints], JSON_THROW_ON_ERROR);
            $bodyBlocks[] = LayoutBuilder::block('script', '', ['src' => '/awt_packages/QuillThemeBridge/assets/render-metadata.js', 'defer' => true, 'data-quill-theme-actions' => $config]);
            $scripts[] = '/awt_packages/Quill/views/assets/js/quill-actions-runtime.js';
        }
        foreach (array_unique($scripts) as $url) if (!isset($seen['script:' . $url])) $bodyBlocks[] = LayoutBuilder::block('script', '', ['src' => $url, 'defer' => true]);
        $identity = new BlockIdentity();
        $headBlocks = $identity->assignBlocks($headBlocks, 'QuillThemeBridge/metadata/head');
        $bodyBlocks = $identity->assignBlocks($bodyBlocks, 'QuillThemeBridge/metadata/body');
        if ($head !== null) foreach ($headBlocks as $block) $head->addBlockChild($block);
        else foreach ($headBlocks as $block) $document->addBlock('quillThemeAssets', $block);
        if ($body !== null) foreach ($bodyBlocks as $block) $body->addBlockChild($block);
        else foreach ($bodyBlocks as $block) $document->addBlock('quillThemeScripts', $block);
    }
}
