(() => {
    'use strict';
    const source = document.querySelector('script[data-quill-theme-style]');
    if (!source || !document.body) return;
    const appearance = JSON.parse(source.dataset.quillThemeStyle);
    document.body.classList.add(...(appearance.bodyClasses || []));
    for (const [name, value] of Object.entries(appearance.variables || {})) document.body.style.setProperty(name, value);
    if (appearance.language) document.documentElement.lang = appearance.language;
})();
