/*
 * General Settings panel — Favicon / Site icon picker.
 *
 * Binds to the #favicon-field block rendered in settings/general.latte.
 */

(function () {
    function boot() {
        const field   = document.getElementById('favicon-field');
        if (!field) return;
        const input   = document.getElementById('favicon-id');
        const pickBtn = document.getElementById('favicon-pick');
        const clear   = document.getElementById('favicon-clear');
        const preview = document.getElementById('favicon-preview');

        if (!input || !pickBtn) return;

        function syncPreview(media) {
            if (media && media.url) {
                preview.innerHTML = '';
                const img = document.createElement('img');
                img.src = media.thumbnail_url || media.url;
                img.alt = media.alt_text || '';
                img.className = 'w-full h-full object-contain';
                preview.appendChild(img);
                preview.hidden = false;
                clear.hidden = false;
            } else if (input.value) {
                if (!preview.querySelector('img')) {
                    preview.innerHTML = '<span class="og-image-pill">Icon selected</span>';
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
