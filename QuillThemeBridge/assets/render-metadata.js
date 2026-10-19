(() => {
    'use strict';
    // Transport configuration only. Quill's existing runtime executes the actions.
    for (const source of document.querySelectorAll('script[data-quill-theme-actions]')) {
        if (source.dataset.quillThemeMounted) continue;
        source.dataset.quillThemeMounted = 'true';
        const script = document.createElement('script');
        script.type = 'application/json'; script.dataset.quillActions = '';
        const config = JSON.parse(source.dataset.quillThemeActions);
        script.textContent = JSON.stringify(config); source.after(script);
        if (window.QuillActions) { script.dataset.quillMounted = 'true'; window.QuillActions.mount(document, config); }
    }
})();
