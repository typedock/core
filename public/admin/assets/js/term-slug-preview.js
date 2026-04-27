(function () {
    function makeSlug(value, fallback) {
        var slug = String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[\s-]+/g, '-')
            .replace(/^-+|-+$/g, '');
        return slug ? encodeURIComponent(slug) : fallback;
    }

    document.querySelectorAll('[data-slug-form]').forEach(function (form) {
        var name = form.querySelector('[data-slug-name]');
        var slug = form.querySelector('[data-slug-input]');
        var preview = form.querySelector('[data-slug-preview]');
        if (!name || !slug || !preview) return;

        var touched = false;
        function sync() {
            var next = touched && slug.value.trim() !== ''
                ? makeSlug(slug.value, '')
                : makeSlug(name.value, 'auto-generated');
            preview.textContent = next;
            if (!touched) slug.placeholder = next;
        }

        slug.addEventListener('input', function () {
            touched = slug.value.trim() !== '';
            sync();
        });
        name.addEventListener('input', sync);
        sync();
    });
})();
