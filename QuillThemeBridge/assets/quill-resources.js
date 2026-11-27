(() => {
    'use strict';
    const source = document.querySelector('script[data-quill-theme-resources]');
    const themeStyles = JSON.parse(source?.dataset.quillThemeStyles || '{}');
    const metadata = JSON.parse(source?.dataset.quillThemeResources || '{}');
    const editorAppearance = JSON.parse(source?.dataset.quillThemeAppearance || '{}');
    const attachedEditors = new WeakSet();
    function groupPicker() {
        const container = document.querySelector('#editor-block-dialog .editor-block-picker');
        if (!container) return;
        // Quill's provider groups already own these cards. Moving them would
        // leave empty provider headings and duplicate the category hierarchy.
        const buttons = [...container.querySelectorAll('button[data-component-id]')].filter(button => metadata[button.dataset.componentId] && !button.closest('[data-component-provider]'));
        if (!buttons.length || buttons.every(button => button.dataset.quillThemeGrouped)) return;
        const groups = new Map();
        for (const button of buttons) {
            const meta = metadata[button.dataset.componentId], key = `${meta.group}:${meta.group_key}`;
            if (!groups.has(key)) groups.set(key, {meta, buttons: []});
            groups.get(key).buttons.push(button);
        }
        for (const old of container.querySelectorAll('[data-quill-theme-group]')) old.remove();
        const anchor = container.firstChild;
        for (const {meta, buttons} of [...groups.values()].sort((a,b) => Number(b.meta.active_theme)-Number(a.meta.active_theme) || a.meta.group_key.localeCompare(b.meta.group_key))) {
            const section = document.createElement('details'); section.className = 'editor-block-category'; section.open = true; section.dataset.quillThemeGroup = meta.group_key;
            const heading = document.createElement('summary'); heading.textContent = `${meta.group} · ${meta.provider}`;
            const grid = document.createElement('div'); grid.className = 'editor-block-grid'; section.append(heading, grid);
            for (const button of buttons) {
                button.dataset.quillThemeGrouped = 'true';
                const provider = button.querySelector('.editor-resource-provider');
                if (provider) provider.textContent = `Provided by ${meta.provider}${meta.active_theme ? ' · Active theme' : ''} · ${meta.resource_type}`;
                grid.append(button);
            }
            container.insertBefore(section, anchor);
        }
    }
    function attach(editor) {
        if (attachedEditors.has(editor)) return;
        attachedEditors.add(editor);
        const panel = document.getElementById('editor-block-dialog');
        if (panel) {
            new MutationObserver(groupPicker).observe(panel, {childList: true, subtree: true});
            groupPicker();
        }
        const appearances = () => {
            const referenced = new Set(), documentThemes = new Map(), scopes = new Map();
            const visit = (blocks, prefix, shared = false) => blocks.forEach((block, index) => {
                const path = `${prefix}.${index}`;
                const component = block._quill?.component;
                const componentTheme = component?.external ? metadata[component.id]?.theme_style : null;
                const isShared = shared || !!component;
                const reference = block.descriptor?.props?.quillThemeBridge;
                if (componentTheme) {
                    referenced.add(componentTheme);
                    scopes.set(path, componentTheme);
                }
                if (Number.isInteger(reference?.themeKey) && typeof reference.pageKey === 'string') {
                    const key = `${reference.themeKey}:${reference.pageKey}`;
                    const appearance = themeStyles[key];
                    if (appearance) {
                        referenced.add(appearance);
                        if (!isShared) {
                            const fingerprint = JSON.stringify([appearance.styles, appearance.bodyClasses, appearance.variables, appearance.language]);
                            documentThemes.set(fingerprint, appearance);
                        }
                    }
                }
                visit(block.children || [], path, isShared);
            });
            editor.getDocument().sections.forEach((section, index) => visit(section.blocks, String(index)));
            const documentTheme = documentThemes.size === 1 ? documentThemes.values().next().value : null;
            const primary = editorAppearance.source || documentTheme || editorAppearance.active;
            return {primary, referenced, scopes};
        };
        const getPreviewAssets = editor.getPreviewAssets.bind(editor);
        editor.getPreviewAssets = () => {
            const assets = getPreviewAssets(), {primary, referenced} = appearances();
            const styles = new Set(primary?.styles || []);
            for (const appearance of referenced) for (const url of appearance.styles || []) styles.add(url);
            for (const url of assets.styles) styles.add(url);
            return { ...assets, styles: [...styles] };
        };
        const appliedClasses = new Map();
        let originalLanguage;
        const prioritizeStyles = doc => {
            const important = declaration => {
                for (const name of [...declaration]) declaration.setProperty(name, declaration.getPropertyValue(name), 'important');
            };
            const visitRules = rules => {
                for (const rule of rules) {
                    if (rule.type === 1 && (rule.selectorText.includes('[data-quill-editor-node=') || /\.quill-scope-[a-f0-9]{64}/.test(rule.selectorText))) important(rule.style);
                    else if (rule.type === 4) visitRules(rule.cssRules);
                }
            };
            for (const style of doc.head.querySelectorAll('style:not([data-quill-theme-variables])')) if (style.sheet) visitRules(style.sheet.cssRules);
            const visitBlocks = (blocks, prefix) => blocks.forEach((block, index) => {
                const path = `${prefix}.${index}`, element = editor.canvas.getElement(path);
                if (element && typeof block.attributes?.style === 'string') {
                    const declaration = doc.createElement('span').style; declaration.cssText = block.attributes.style;
                    for (const name of [...declaration]) element.style.setProperty(name, declaration.getPropertyValue(name), 'important');
                }
                visitBlocks(block.children || [], path);
            });
            const state = editor.getDocument();
            state.sections.forEach((section, index) => visitBlocks(section.blocks, String(index)));
            for (const name of Object.keys(state.settings?.styles || {})) {
                const value = doc.body.style.getPropertyValue(name);
                if (value) doc.body.style.setProperty(name, value, 'important');
            }
        };
        editor.on('canvasrendered', () => {
            const doc = document.getElementById('editor-canvas')?.contentDocument;
            if (!doc?.body) return;
            prioritizeStyles(doc);
            originalLanguage ??= doc.documentElement.lang;
            for (const [element, classes] of appliedClasses) element.classList.remove(...classes);
            appliedClasses.clear();
            doc.head.querySelector('style[data-quill-theme-variables]')?.remove();
            doc.documentElement.lang = originalLanguage;
            const {primary, scopes} = appearances();
            const variableRules = [];
            const applyAppearance = (element, selector, appearance) => {
                const classes = (appearance.bodyClasses || []).filter(name => !element.classList.contains(name));
                element.classList.add(...classes);
                appliedClasses.set(element, classes);
                const variables = Object.entries(appearance.variables || {});
                if (variables.length) variableRules.push(selector + '{' + variables.map(([name, value]) => `${name}:${value};`).join('') + '}');
            };
            if (primary) {
                applyAppearance(doc.body, ':root body', primary);
                if (primary.language) doc.documentElement.lang = primary.language;
            }
            for (const [path, appearance] of scopes) {
                const selector = `[data-quill-component][data-quill-editor-node="${path}"]`;
                const element = doc.querySelector(selector);
                if (element) applyAppearance(element, selector, appearance);
            }
            if (variableRules.length) {
                const style = doc.createElement('style');
                style.dataset.quillThemeVariables = '';
                style.textContent = variableRules.join('\n');
                doc.head.append(style);
            }
        });
        // Refresh a canvas that was rendered before this event extension attached.
        editor.dispatchEvent(new Event('rendererschange'));
        // Existing state/history handles all content edits. Clearing identities only on insertion is a local-copy operation.
        const insert = editor.state.insertBlocks.bind(editor.state);
        editor.state.insertBlocks = (section, templates, ...args) => {
            const copies = structuredClone(templates);
            const clear = blocks => blocks.forEach(block => {
                if (block._quill?.editor) { delete block._quill.editor.originalUrpcUid; delete block._quill.editor.originalUrpcSource; }
                const reference = block.descriptor?.props?.quillThemeBridge;
                if (reference) { delete reference.uid; delete reference.source; }
                clear(block.children || []);
            });
            clear(copies); return insert(section, copies, ...args);
        };
    }
    window.addEventListener('quill.editor.ready', event => attach(event.detail.editor));
    if (window.QuillEditor) attach(window.QuillEditor);
})();
