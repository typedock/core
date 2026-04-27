/*
 * Media library page — drop-zone uploads + incremental grid refresh.
 *
 * This file is referenced from admin/pages/media/index.latte. All heavy
 * lifting (actual fetch, drag-drop wiring, modal picker) lives in
 * media-picker.js, which exposes window.TypeDockMedia.
 *
 * We only run on the library page itself — `#media-upload-area` is the
 * drop-zone element rendered by the Latte template and is our sole trigger.
 */

(function () {
    function boot() {
        const area = document.getElementById('media-upload-area');
        if (!area) return;
        if (!window.TypeDockMedia) {
            console.error('media-picker.js must be loaded before media-upload.js');
            return;
        }

        window.TypeDockMedia.attachDropZone(area, {
            onUploaded(media) {
                hideEmptyState();
                appendMediaItem(media);
                showSuccess(`${media.original_filename || 'File'} uploaded.`);
            },
            onError(err, file) {
                showError(`${file.name}: ${err.message}`);
            },
        });
    }

    function hideEmptyState() {
        const empty = document.querySelector('.empty-state');
        if (empty) empty.remove();

        if (!document.getElementById('media-grid')) {
            // First upload on an empty library — mount a fresh grid.
            const grid = document.createElement('div');
            grid.className = 'media-grid';
            grid.id = 'media-grid';
            const area = document.getElementById('media-upload-area');
            area.parentNode.insertBefore(grid, area.nextSibling);
        }
    }

    function appendMediaItem(media) {
        const grid = document.getElementById('media-grid');
        if (!grid) return;

        const csrf = document.querySelector('#media-upload-area').dataset.csrf || '';
        const isImage = (media.mime_type || '').startsWith('image/');
        const el = document.createElement('div');
        el.className = 'media-item';
        el.dataset.id = media.id;
        el.innerHTML = `
            ${isImage
                ? `<img src="${attr(media.url)}" alt="${attr(media.alt_text || '')}" loading="lazy">`
                : `<div class="media-icon">${text(media.mime_type || 'file')}</div>`}
            <div class="media-info">
                <span class="media-name" title="${attr(media.original_filename || '')}">${text(media.original_filename || '')}</span>
            </div>
            <div class="media-actions">
                <button class="btn btn-ghost btn-xs copy-url-btn" data-url="${attr(media.url)}">Copy URL</button>
                <button class="btn btn-ghost btn-xs copy-url-btn" data-url="${attr(media.id)}" title="Copy this item's ID (used by the SEO panel's OG image field)">Copy ID</button>
                <form method="post" action="/admin/media/delete/${attr(media.id)}" class="inline ml-auto">
                    <input type="hidden" name="_csrf_token" value="${attr(csrf)}">
                    <button type="submit" class="btn btn-danger btn-xs" onclick="return confirm('Delete this item?')" aria-label="Delete">&times;</button>
                </form>
            </div>
        `;
        // Newest first — matches MediaService::list ORDER BY created_at DESC
        grid.insertBefore(el, grid.firstChild);

        // Re-bind clipboard handlers for the new buttons. admin.js attaches
        // once on DOMContentLoaded, so newly-injected buttons need wiring.
        el.querySelectorAll('.copy-url-btn').forEach(bindCopyButton);
    }

    function bindCopyButton(btn) {
        const label = btn.textContent;
        btn.addEventListener('click', () => {
            navigator.clipboard.writeText(btn.dataset.url).then(() => {
                btn.textContent = 'Copied';
                setTimeout(() => { btn.textContent = label; }, 2000);
            });
        });
    }

    function showError(message) {
        showNotice('media-upload-error', 'alert alert-error mb-4', message);
    }

    function showSuccess(message) {
        showNotice('media-upload-success', 'alert alert-success mb-4', message);
    }

    function showNotice(id, className, message) {
        const otherId = id === 'media-upload-error' ? 'media-upload-success' : 'media-upload-error';
        const other = document.getElementById(otherId);
        if (other) other.remove();

        let bar = document.getElementById(id);
        if (!bar) {
            bar = document.createElement('div');
            bar.id = id;
            bar.className = className;
            const main = document.querySelector('.admin-main') || document.body;
            main.insertBefore(bar, main.firstChild);
        }
        bar.textContent = message;
    }

    function text(s) {
        return String(s ?? '').replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
    }
    function attr(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
