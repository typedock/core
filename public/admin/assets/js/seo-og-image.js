/*
 * SEO panel — OG image picker.
 *
 * Binds to the #og-image-field block rendered in posts/edit.latte. Replaces
 * the previous "copy the UUID yourself from the Media Library" workflow
 * with a click-to-pick flow against the shared media picker.
 */

(function () {
    function boot() {
        const field   = document.getElementById('og-image-field');
        if (!field) return;
        const input   = document.getElementById('og-image-id');
        const pickBtn = document.getElementById('og-image-pick');
        const clear   = document.getElementById('og-image-clear');
        const preview = document.getElementById('og-image-preview');

        if (!input || !pickBtn) return;

        // Sync preview state with the current input value. We can't render
        // a real thumbnail without a GET-by-id endpoint, so on first load we
        // just show a subtle "Image selected" pill if a UUID is present.
        function syncPreview(media) {
            if (media && media.url) {
                preview.innerHTML = '';
                const img = document.createElement('img');
                img.src = media.thumbnail_url || media.url;
                img.alt = media.alt_text || '';
                preview.appendChild(img);
                preview.hidden = false;
                clear.hidden = false;
            } else if (input.value) {
                if (!preview.querySelector('img')) {
                    preview.innerHTML = '<span class="og-image-pill">Image selected</span>';
                }
                preview.hidden = false;
                clear.hidden = false;
            } else {
                preview.innerHTML = '';
                preview.hidden = true;
                clear.hidden = true;
            }
        }

        pickBtn.addEventListener('click', () => {
            const picker = window.TypeDockMedia;
            if (!picker) {
                alert('Media picker failed to load.');
                return;
            }
            picker.openPicker({
                accept: 'image',
                onSelect(media) {
                    input.value = media.id;
                    syncPreview(media);
                },
            });
        });

        clear.addEventListener('click', () => {
            input.value = '';
            syncPreview(null);
        });

        syncPreview(null);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
