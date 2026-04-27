(function () {
    var root = document.querySelector('[data-user-avatar]');
    if (!root) return;

    var input = root.querySelector('input[name="avatar_media_id"]');
    var pick = root.querySelector('[data-avatar-pick]');
    var clear = root.querySelector('[data-avatar-clear]');
    var preview = root.querySelector('[data-avatar-preview]');

    function render(url) {
        if (!preview) return;
        if (!url) {
            preview.hidden = true;
            preview.innerHTML = '';
            return;
        }
        preview.hidden = false;
        preview.innerHTML = '<img src="' + escapeAttr(url) + '" alt="" loading="lazy">';
    }

    pick?.addEventListener('click', function () {
        window.TypeDockMedia?.openPicker({
            accept: 'image',
            onSelect: function (media) {
                if (!media) return;
                input.value = media.id || '';
                render(media.thumbnail_url || media.url || '');
                if (clear) clear.hidden = false;
            },
        });
    });

    clear?.addEventListener('click', function () {
        input.value = '';
        render('');
        clear.hidden = true;
    });

    render(root.dataset.avatarUrl || '');

    function escapeAttr(value) {
        return String(value || '').replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
})();
