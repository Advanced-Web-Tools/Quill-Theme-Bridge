(() => {
    'use strict';
    const source = document.querySelector('script[data-quill-theme-resources]');
    const themeStyles = JSON.parse(source?.dataset.quillThemeStyles || '{}');
    const metadata = JSON.parse(source?.dataset.quillThemeResources || '{}');
    function groupPicker() {
        const container = document.querySelector('#editor-block-dialog .editor-block-picker');
        if (!container) return;
        const buttons = [...container.querySelectorAll('button[data-component-id]')].filter(button => metadata[button.dataset.componentId]);
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
        const panel = document.getElementById('editor-block-dialog');
        if (!panel || panel.dataset.quillThemeObserved) return;
        panel.dataset.quillThemeObserved = 'true';
        new MutationObserver(groupPicker).observe(panel, {childList: true, subtree: true});
        groupPicker();
        const references = () => {
            const found = new Map();
            const visit = blocks => blocks.forEach(block => {
                const reference = block.descriptor?.props?.quillThemeBridge;
                if (reference && Number.isInteger(reference.themeKey) && typeof reference.pageKey === 'string') {
                    const key = `${reference.themeKey}:${reference.pageKey}`;
                    if (themeStyles[key]) found.set(key, themeStyles[key]);
                }
                visit(block.children || []);
            });
            editor.getDocument().sections.forEach(section => visit(section.blocks)); return found;
        };
        const getPreviewAssets = editor.getPreviewAssets.bind(editor);
        editor.getPreviewAssets = () => {
            const assets = getPreviewAssets(), styles = new Set(assets.styles);
            for (const appearance of references().values()) for (const url of appearance.styles || []) styles.add(url);
            return { ...assets, styles: [...styles] }; // Theme scripts never execute in the editor canvas.
        };
        let appliedClasses = [], appliedVariables = [], originalLanguage;
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
            for (const style of doc.head.querySelectorAll('style')) if (style.sheet) visitRules(style.sheet.cssRules);
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
            appliedClasses.forEach(name => doc.body.classList.remove(name));
            appliedVariables.forEach(name => doc.body.style.removeProperty(name));
            appliedClasses = []; appliedVariables = []; doc.documentElement.lang = originalLanguage;
            const appearances = [...new Map([...references()].map(([key, appearance]) => [
                `${key.split(':')[0]}:${JSON.stringify([appearance.bodyClasses, appearance.variables, appearance.language])}`, appearance,
            ])).values()];
            if (appearances.length !== 1) return;
            const appearance = appearances[0];
            appliedClasses = appearance.bodyClasses || []; doc.body.classList.add(...appliedClasses);
            for (const [name, value] of Object.entries(appearance.variables || {})) { doc.body.style.setProperty(name, value); appliedVariables.push(name); }
            if (appearance.language) doc.documentElement.lang = appearance.language;
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
