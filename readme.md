# QuillThemeBridge

AWT package connecting ThemeEngine, Quill and URPC through their existing public classes and events. **No dependency files are changed.** All adapters, event listeners, public assets and link storage belong to this package. There are no bridge controllers, routes, administrative views, modals or frontend application.

Requirements: PHP 8.2+, AWT 27+, ThemeEngine 1.0.5+, Quill 1.0.0+, URPC 1.0.0+. Install the dependencies normally, then install/update and enable the bridge through AWT PackageManager. Install/update hooks create only `quill_theme_bridge_link`. No compatibility patch installer, class aliases, vendor namespace replacements or copied upstream classes are needed.

## Integration

| Existing event | Bridge behavior |
| --- | --- |
| `quill.external.pages.request` | Register source page documents for every valid available enabled theme. |
| `quill.external.components.request` | Register global layout parts and widgets of every available theme, including currently hidden parts. |
| `quill.external.color_palettes.request` | Discover all palettes of the active available theme through supplied or explicitly registered adapters. |
| `quill.page.document.request` | Compose the already converted published Quill document into the active theme's global layout and retain Quill assets. |
| `theme_engine.page.ready` | Apply published component changes in their native layout slots/widget placements, then page-specific published UID replacements for the actually rendered theme/page. Applies in public rendering and ThemeEngine preview. |
| AWT `renderer.ready` | Group resources inside existing Quill Pages → Other and Components → External views and attach the existing editor's resource extension. |
| Browser `quill.editor.ready` | Extend the existing editor instance's insertion flow and component picker; use its normal history/save/publish logic. |

ThemeEngine final classes are consumed by composition. `ThemeDocuments` uses public Engine configuration, ThemeRegistry file resolution, JsonSourceCache, ISectionProvider, SidebarWidgetRegistry, LayoutBuilder and BlockIdentity. Page source acquisition calls `Engine::createDocument`; no page controller action, widget block renderer or full page renderer is executed by registration. The bridge does not reconstruct ThemeEngine configuration or maintain a second theme engine.

Global composition keeps every Quill section's blocks, the native outer layout and slot order, global visibility and global widget order. It does not select an arbitrary ThemeEngine page or apply its local overrides. Components use ThemeEngine's own native slot/widget wrappers, including multi-root source content. A failure in one resource/theme is diagnosed through `error_log` without removing other themes.

## Stable identities and grouping

Keys are `QuillThemeBridge:t{packageId}:{page|part|widget|palette}:{hex(stableKey)}`. Theme identity is ThemeRecord's persisted package ID; page identity is PageDefinition's key; part identity is its logical slot; widget identity is SidebarWidgetDefinition's key. Labels, routes, activation and list positions do not enter stable keys. Provider is the theme's readable definition label. Route templates are retained without parameter substitution, enumeration or new routes.

`ExternalDocument` remains unchanged. Bridge-owned metadata associates stable resource keys with Active theme / Other themes and Layout part / Widget. The public AWT DOM hook groups the existing Quill cards. A small extension groups the existing component picker's buttons, retaining their click handlers and local-copy operation. Active theme is explicitly marked in text. Existing providers without bridge metadata remain untouched.

## Explicit linked editing

From another runtime after waiting for QuillThemeBridge:

```php
$editing = $this->getShared('QuillThemeBridge', 'linkedEditing', \QuillThemeBridge\services\LinkedEditing::class);
$page = $editing->create($themePackageId, $themePageKey, 'Linked draft', $csrfToken);
// Continue in Quill's existing editor: /quill/editor/page/{id}
$id = $page->getKey();
```

`create()` imports through Quill's converters, adds original identity to the existing editor metadata, then uses `EditorDocumentSaver` to create a draft. The explicit source link is inserted in the same database transaction. Publish through the existing Quill editor. `link(themeId, pageKey, localQuillPageId, csrfToken)` explicitly links an existing local page; only source UIDs actually preserved on that page can match. `unlink(themeId, pageKey, expectedQuillPageId, csrfToken)` compares the expected target.

Authentication, permission level 1 and CSRF validation use existing AWT Admin/Csrf checks. The source primary key rejects concurrent duplicate links; replacement requires explicit unlink/link. Ordinary local copies remain independent and never insert a link. The current Quill dashboard exposes **Edit in Quill**, which creates its own explicit source-bound page through `ExternalPageOverrides`; the bridge consumes that public API as well. A local-copy action is not supplied by the bridge when the installed Quill UI does not expose one. Discovery and rendering perform no database writes.

## Original UID, snapshots and render metadata

During explicit linked import, the bridge reads the exact UID/source mapping supplied by `UrpcToQuillConverter` and attaches it as `editor.originalUrpcUid` and `editor.originalUrpcSource` before the first save. Existing Quill editor serialization, mapper, saver, reload and snapshot manifests preserve this metadata without a schema change. The bridge's `PublishedPageConversion` uses Quill's existing `SnapshotQuillContentSource` and `QuillToUrpcConverter`, then restores original UIDs through the URPC descriptor API. It never infers identity from positions, HTML IDs or database IDs.

The editor extension clears source identity only on newly inserted/duplicated copies. Existing history snapshots preserve the corresponding undo/redo state. The server also rejects ambiguous source/target UIDs and replacement ancestors containing ambiguous children. Whole edited subtrees replace their matched parents; old descendants are not reapplied. Missing or unmatched blocks are not deletion/insertion commands.

Only the exact page publication is read. `StrictPublishedContentSource` implements Quill's public content-source interfaces and accepts only independently published shared components; captured working component drafts cannot become replacements. No link, missing publication, unmatched identity or conversion failure leaves the theme's original content visible. Preview uses its rendered theme's identity. A new working draft cannot alter the chosen published UID mapping.

`RenderMetadata` adds ordinary URPC link/script nodes carrying required styles, fonts, scripts and animation assets. It does not render HTML itself. Generated CSS uses a `data:text/css;base64` stylesheet because URPC forbids raw style/script text nodes. A static bridge script transports JSON action configuration into the existing Quill action runtime; no editor code appears in public output. URL assets already present in the composed theme are deduplicated. Connected-data descriptors continue through Quill's existing URPC event listener.

## Native Quill external editing

The inspected Quill also exposes the public `ExternalPageOverrides` service. **Edit in Quill** stores an explicit, unique `source_type=urpc` / `source_key` association. The source key is exactly `Keys::source(themePackageId, pageKey)`, so it supplies the same stable theme/page scope as a bridge link. The bridge uses the service’s published conversion API directly; it does not invoke a page renderer or interpret override records itself.

On ThemeEngine rendering, both association mechanisms are checked. If a native external page and a different bridge-linked page target the same source, rendering diagnoses the conflict and keeps the original theme content. The linked-editing API also rejects creation/linking when a native association already exists. Discovery never creates either association.

The native editor owns UID patches and their published snapshot manifests, including the real `urpc_uid` columns. Install/update Quill through its normal lifecycle so its own migrations are applied. The bridge never migrates Quill tables. Native source editing currently allows editing existing blocks; adding, deleting and moving blocks are restricted by Quill. Bridge-linked local drafts continue to use the ordinary editor.

Ordinary imported copies retain each original UID and source in `descriptor.props.quillThemeBridge` through Quill’s existing mapper, save and snapshot APIs. This also allows an explicit later link without positional inference. Source metadata includes its original theme/page, so copying a document from another theme cannot silently override matching UIDs. Duplicating/inserting local blocks clears their source UID in both descriptor and editor metadata, while retaining the theme association needed for CSS. Undo/redo uses the existing editor history.

## Published theme component changes

Quill’s current **Components → External → Edit in Quill** operation owns a separate source-bound component publication. The bridge reads it through `ExternalPageOverrides::componentSource()` using `kind=component` identities; ordinary local component copies remain independent.

Published changes apply both to ThemeEngine pages and to the theme layout composed around published Quill pages. They do not require a linked page override. Only existing native slots and configured widget placements are updated; publication never enables a disabled part or inserts an unconfigured widget. Page-local section sources retain ownership of their slots. Resource lookup always includes the rendered theme’s persisted identity, component type and key.

Native wrappers are associated through ThemeEngine’s explicit slot/widget source namespaces and keys. Edited wrapper fields retain the actual placement’s UID, source namespace, unrelated runtime attributes and configured sidebar widgets. Identical instances receive the complete published source subtree; context-dependent source trees receive conservative matches by original UID. This comparison does not create a positional UID mapping. Unmatched source blocks remain unchanged. Theme providers that need page-specific data may therefore expose only the matching shared subset of their component edits.

Each placement uses Quill’s normal component expander through a bridge-owned content-source adapter. Quill scopes actions, targets, styles and animations independently, including repeated placements of the same widget. Assets are transported through ordinary URPC nodes and deduplicated against the theme, other components and retained Quill page assets. Source registration stays read-only and returns original source documents, so opening/saving the editor does not recursively import published replacements. Standalone component previews remain components and are not wrapped in a complete theme layout.

Components are resolved before page-specific replacements. Missing/unpublished component versions leave original content; failed component conversions are diagnosed independently. A later draft cannot change the chosen published component source. Conversion caches exist only for the current composition.

## Theme CSS and saved appearance settings

Every block of every page/component source carries its original URPC UID, source namespace and stable theme/page association in the extensible URPC descriptor property `quillThemeBridge`. Quill's existing import/editor/save/snapshot flow preserves that association. Missing native wrapper UIDs are assigned through URPC before registration, preserving existing source identities. Duplicate source UIDs reject that resource with a diagnostic. Asset URLs and settings are resolved from the enabled source theme's current public definition and `Engine::settings()`, rather than from client-supplied URLs or copied working settings.

The editor's existing `getPreviewAssets()` flow adds the source theme's declared CSS once. Its existing canvas receives explicitly mapped body classes, CSS variables and document language. Theme scripts are never executed in the editor. Public/external previews include declared theme CSS/scripts through normal URPC asset nodes; Quill's retained publication assets are deduplicated using a bridge-owned `URPCBlockDescriptor` extension with runtime-only state. No extra Quill schema fields or wrappers are introduced.

Quill pages composed into the active theme use that theme's normalized saved appearance settings. Source previews/local copies use their own theme's appearance. Settings remain owned and edited by ThemeEngine; the bridge never writes them or copies them into palette preferences.

StillTheme is supported by an explicit bridge-owned adapter based on its actual `GalleryPage` contract: `palette` maps to `still-site` plus `still-paper`/`still-white`/`still-dark`, and `language` maps to the document language. No protected controller methods are called. Existing custom theme adapters take precedence over this supplied adapter.

For other themes whose visual rules exist only in custom controller code, register an explicit adapter from an enabled package runtime after waiting for QuillThemeBridge:

```php
$styles = $this->getShared('QuillThemeBridge', 'themeStyles', \QuillThemeBridge\services\ThemeStyles::class);
$styles->register($themePackageId, new class implements \QuillThemeBridge\api\IThemeStyleProvider {
    public function style(\ThemeEngine\api\ThemePageContext $context): \QuillThemeBridge\api\ThemeStyle
    {
        // Use the real setting keys and supported mapping of this particular theme.
        return new \QuillThemeBridge\api\ThemeStyle(
            bodyClasses: ['my-theme'],
            variables: ['--brand-accent' => $context->settings['accent']],
        );
    }
});
```

The example assumes that this theme declares an `accent` color setting. No generic setting-to-CSS inference is performed. Invalid adapters are diagnosed while preserving the theme's declared CSS. Mixed themes can contribute CSS to the same document; conflicting appearance contexts are not arbitrarily promoted to the editor body's global settings. Normalized setting values are passed to existing section/widget providers as before.

## Priority for edited styles

Bridge-rendered Quill block declarations use `!important` so normal theme selectors do not override edits. This covers generated block CSS, hover/focus states, responsive rules, edited page styles and inline declarations inside Quill content. ThemeEngine source stylesheets are unchanged, and priority is added at render time rather than written into saved documents or publications. Existing priority flags are retained without duplication.

The editor preview applies the same priority through CSSOM on Quill-generated rules and authored inline declarations, preserving the editor state, undo and save values. Keyframe definitions and unrelated stylesheet rules are not rewritten. Quill pages composed into a theme keep their existing assets/actions and receive a CSS-only priority counterpart through the normal URPC asset contract. Published theme page/component replacements use that same transport.

## Unicode editor transport

Quill’s editor template emits Unicode in its JSON bootstrap. AWT’s DOM `saveHTML()` serialization can turn those characters into HTML entities even inside a JSON script, where browsers keep the entity spelling as literal data. For example, a source `↓` can become the saved text `&darr;` after opening and saving the editor.

The bridge’s existing `renderer.ready` hook reserializes `quill-editor-data` using JSON Unicode escapes before final HTML serialization. It preserves JSON object/list types, URPC identities, intentional literal entity text and HTML-like text. No global HTML decoding or database writes are performed. The correction applies to both page and component editor bootstraps and remains stable over repeated DOM serialization.

Already saved entity spellings cannot be distinguished reliably from intentionally literal text. The bridge does not rewrite historical drafts/publications. After installing this fix, replace the affected text with the intended Unicode character in Quill and publish again.

## Theme color palettes

The bridge automatically exposes all three named variants of the installed **StillTheme** and **MonoTheme** when that theme is active. Still contributes Warm paper, Gallery white and Dark gallery; Mono contributes Paper / ink, Pure white / black and Black / paper. Labels come from each actual theme definition. The supplied adapters read the literal Paper, Ink, Muted and Line colors from `assets/theme.css`, merging variant overrides with base values. They do not copy hard-coded color values, execute controllers, write settings, fetch remote URLs or scan arbitrary stylesheets. A new registry request reads the current source values.

ThemeEngine itself still has no generic palette declaration. `KnownThemePalettes` supports the inspected standalone class rules of these two themes only; an incompatible source contract is diagnosed rather than guessed. Other themes can expose their actual palettes through a bridge-owned provider, registered by an enabled package runtime after waiting for QuillThemeBridge:

```php
$palettes = $this->getShared('QuillThemeBridge', 'palettes', \QuillThemeBridge\services\PaletteCatalog::class);
$palettes->registerProvider($themePackageId, new class implements \QuillThemeBridge\api\IThemePaletteProvider {
    public function palettes(\ThemeEngine\api\ThemePageContext $context): array
    {
        // Read the actual palette contract of this theme; this example declares one explicitly.
        return [new \QuillThemeBridge\api\ThemePalette('brand', 'Brand', [
            ['id' => 'primary', 'name' => 'Primary', 'value' => '#2277ee'],
            ['id' => 'hero', 'name' => 'Hero', 'value' => 'linear-gradient(90deg, #2277ee, #6633cc)'],
        ])];
    }
});
```

A registered provider takes precedence over the supplied adapter for that theme. It receives the selected theme's normalized settings and a read-only discovery context, without a concrete page. Static contributions remain supported with `$palettes->register($themePackageId, 'brand', 'Brand', $colors)`; their keys must not collide with provider palette keys. Invalid or ambiguous definitions are diagnosed before partially adding the theme's palettes.

The registry offers only the active available theme's palettes. Provider is always its readable definition label. Keys include persisted theme identity plus the stable source palette key; color IDs include that palette key and a stable source color ID. Renaming labels or switching themes never changes these identities. Every paint passes Quill's existing validation, including supported gradients.

Quill supplies the existing **External**, provider labels, **Explore** and **Make default** behavior. Registration never selects a default or changes applied paints. A previous default that is unavailable after a theme switch falls back to Explore; opening and saving documents continue normally. Palettes are external resources, not URPC blocks, so they use Quill palette/color IDs; every page and component block still carries its URPC UID.

## Isolation and remaining limits

Source documents are copied for composition and replacement. Weak maps track event/document instances without a persistent process-wide render flag. No finished-document cache is introduced. ThemeEngine's existing JSON source cache may write cache files; bridge rendering does not write database records.

- Automatic palette discovery is supplied for StillTheme and MonoTheme. Other themes require an explicit palette provider or static contributions until ThemeEngine exposes a general palette contract.
- Source providers receive an empty page key/data for global composition and component discovery. A provider requiring a concrete route or page can fail and is diagnosed; the bridge does not invent a page/parameter. In particular, the upstream Current page widget does not support that global context.
- Quill pages lacking a publication keep their existing upstream behavior; the bridge does not wrap their working content. Theme overrides and ThemeEngine previews strictly require published snapshots.
- Sites using CSP must permit `data:` stylesheets, or supply a compatible asset transport. Generated CSS is not silently dropped.
- URPC source UIDs are content/structure-derived. An upstream theme update changing those UIDs makes old replacements stop matching; no positional migration is attempted.
- Quill's converter still determines support for custom settings/bindings. No parallel resolver, save system or renderer is supplied.

The package has been tested against unmodified local dependencies with real AWT/Quill SQLite persistence, URPC rendering and Chrome. Production MySQL installation/deployment is not claimed.

## Development

```sh
php tests/integration.php
php tests/palettes.php
php tests/components.php
php tests/unicode.php
php tests/style-priority.php
php tests/dependency-integrity.php
python3 tests/editor_browser.py
php tools/build-package.php
```

The development checks expect sibling package repositories and AWT Framework; `AWT_FRAMEWORK_CLASSES` can override the framework classes path. Palette checks also read the installed StillTheme and MonoTheme definitions/CSS from the sibling Framework app. Chrome and Python `websockets` are required for browser checks. The build command creates the installable ZIP under `releases/` without deployment.
