(function () {
    // Mirrors TermSlugger::normalize(). Deliberately not encodeURIComponent'd:
    // slugs are stored decoded, because that is the form the router hands a
    // controller. Showing the escaped form here would preview something the
    // server never writes.
    //
    // Invisible letters go first, so the separators that surrounded them
    // collapse together instead of leaving `--`; no \p{M}, matching the
    // server, which refuses combining marks because nothing normalises
    // Unicode and the decomposed form would be a second distinct slug.
    function makeSlug(value, fallback) {
        var slug = String(value || '')
            .trim()
            .toLowerCase()
            .replace(/[\u115F\u1160\u3164\uFFA0]+/g, '')
            .replace(/[^\p{L}\p{N}]+/gu, '-')
            .replace(/^-+|-+$/g, '');
        return slug ? slug : fallback;
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
