/*
 * TypeDock shared media picker.
 * -----------------------------
 * Single source of truth for "pick / upload a media file" across the admin:
 *   - /admin/media (library page drop-zone + grid refresh)
 *   - Block editor "Insert image" toolbar button
 *   - SEO panel OG image field
 *
 * Contract:
 *   window.TypeDockMedia.openPicker({ accept, onSelect })
 *     accept    - 'image' | 'all'  (server-side filter, defaults to 'image')
 *     onSelect  - fn(mediaItem)    mediaItem fields: id, url, thumbnail_url,
 *                                   mime_type, original_filename, alt_text
 *
 *   window.TypeDockMedia.attachDropZone(rootEl, { onUploaded })
 *     Turns an element (and its <input type="file">) into a drag-drop
 *     uploader. Fires onUploaded(mediaItem) for each successful upload.
 *     Used by the library page so new uploads append to the visible grid
 *     without a full reload. Reads CSRF token from rootEl.dataset.csrf and
 *     upload URL from rootEl.dataset.uploadUrl.
 *
 * Endpoints used:
 *   POST /admin/api/media/upload   (multipart, returns { ok, media })
 *   GET  /admin/api/media?q=&type= (returns { ok, items, total, page })
 *
 * Why a plain ESM module + a global: admin.js is loaded as a classic script
 * and the picker is invoked from inline handlers + editor.js. Exporting onto
 * window.TypeDockMedia lets both reach it without a build step.
 */

const UPLOAD_URL = '/admin/api/media/upload';
const BROWSE_URL = '/admin/api/media';

function csrfTokenFromDom() {
    // Every admin POST carries a hidden _csrf_token. Grab the first one as a
    // reasonable default for uploads triggered from pages that don't pass a
    // token explicitly (e.g. the block editor).
    const el = document.querySelector('input[name="_csrf_token"]');
    return el ? el.value : '';
}

async function uploadFile(file, { csrf, uploadUrl } = {}) {
    const form = new FormData();
    form.append('file', file);
    form.append('_csrf_token', csrf || csrfTokenFromDom());

    const res = await fetch(uploadUrl || UPLOAD_URL, {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
    });

    let body = null;
    try { body = await res.json(); } catch (_) { /* fall through */ }

    if (!res.ok || !body || body.ok === false) {
        const msg = body?.errors?.file?.[0]
            || body?.error
            || `Upload failed (HTTP ${res.status})`;
        throw new Error(msg);
    }
    return body.media;
}

async function listMedia({ page = 1, q = '', type = 'image' } = {}) {
    const url = new URL(BROWSE_URL, window.location.origin);
    url.searchParams.set('page', String(page));
    url.searchParams.set('per_page', '40');
    url.searchParams.set('type', type);
    if (q) url.searchParams.set('q', q);

    const res = await fetch(url.toString(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    if (!res.ok) throw new Error(`Failed to load media (HTTP ${res.status})`);
    return res.json();
}

/* ---------- Drop zone (library page) ---------- */

function attachDropZone(root, { onUploaded, onError } = {}) {
    if (!root || root.dataset.tdDropzoneInit === '1') return;
    root.dataset.tdDropzoneInit = '1';

    const input = root.querySelector('input[type="file"]');
    const label = root.querySelector('.upload-drop-zone');
    const uploadUrl = root.dataset.uploadUrl || UPLOAD_URL;
    const csrf = root.dataset.csrf || csrfTokenFromDom();

    async function handleFiles(fileList) {
        const files = Array.from(fileList || []);
        if (!files.length) return;
        root.classList.add('is-uploading');
        for (const file of files) {
            try {
                const media = await uploadFile(file, { csrf, uploadUrl });
                if (onUploaded) onUploaded(media);
            } catch (err) {
                if (onError) onError(err, file);
                else alert(`${file.name}: ${err.message}`);
            }
        }
        root.classList.remove('is-uploading');
    }

    if (input) {
        input.addEventListener('change', (e) => {
            handleFiles(e.target.files);
            e.target.value = ''; // allow re-uploading the same file
        });
    }

    if (label) {
        ['dragenter', 'dragover'].forEach((ev) => label.addEventListener(ev, (e) => {
            e.preventDefault();
            label.classList.add('is-dragover');
        }));
        ['dragleave', 'dragend', 'drop'].forEach((ev) => label.addEventListener(ev, (e) => {
            e.preventDefault();
            label.classList.remove('is-dragover');
        }));
        label.addEventListener('drop', (e) => {
            handleFiles(e.dataTransfer?.files);
        });
    }
}

/* ---------- Picker modal ---------- */

let modalRoot = null;

function ensureModalRoot() {
    if (modalRoot) return modalRoot;
    modalRoot = document.createElement('div');
    modalRoot.className = 'td-modal-root';
    modalRoot.hidden = true;
    document.body.appendChild(modalRoot);
    return modalRoot;
}

function renderModalShell() {
    const root = ensureModalRoot();
    root.innerHTML = `
        <div class="td-modal-backdrop" data-close></div>
        <div class="td-modal" role="dialog" aria-modal="true" aria-labelledby="td-picker-title">
            <div class="td-modal-header">
                <h2 id="td-picker-title">Select media</h2>
                <button type="button" class="td-modal-close" data-close aria-label="Close">&times;</button>
            </div>
            <div class="td-modal-toolbar">
                <label class="btn btn-primary td-picker-upload">
                    <input type="file" accept="image/*" hidden>
                    <span>Upload new</span>
                </label>
                <input type="search" class="td-picker-search" placeholder="Search by filename or alt text…">
                <span class="td-picker-status"></span>
            </div>
            <div class="td-modal-body">
                <div class="td-picker-grid" role="listbox" aria-label="Media items"></div>
            </div>
            <div class="td-modal-footer">
                <button type="button" class="btn btn-ghost" data-close>Cancel</button>
                <button type="button" class="btn btn-primary td-picker-confirm" disabled>Insert</button>
            </div>
        </div>
    `;
    return root;
}

function openPicker({ accept = 'image', onSelect } = {}) {
    const root = renderModalShell();
    root.hidden = false;
    document.body.classList.add('td-modal-open');

    const grid     = root.querySelector('.td-picker-grid');
    const search   = root.querySelector('.td-picker-search');
    const status   = root.querySelector('.td-picker-status');
    const confirm  = root.querySelector('.td-picker-confirm');
    const uploadIn = root.querySelector('.td-picker-upload input[type="file"]');

    let items = [];
    let selected = null;
    let searchTimer = null;

    function close() {
        root.hidden = true;
        document.body.classList.remove('td-modal-open');
        document.removeEventListener('keydown', onKey);
        root.innerHTML = '';
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);

    root.querySelectorAll('[data-close]').forEach((el) => {
        el.addEventListener('click', close);
    });

    function renderGrid() {
        if (!items.length) {
            grid.innerHTML = '<div class="td-picker-empty">No media found. Upload one to get started.</div>';
            return;
        }
        grid.innerHTML = items.map((item) => {
            const thumb = item.thumbnail_url || item.url;
            const isImage = (item.mime_type || '').startsWith('image/');
            const preview = isImage
                ? `<img src="${escapeAttr(thumb)}" alt="${escapeAttr(item.alt_text || '')}" loading="lazy">`
                : `<div class="td-picker-file">${escapeHtml(item.mime_type || 'file')}</div>`;
            return `
                <button type="button" class="td-picker-item" data-id="${escapeAttr(item.id)}" role="option" aria-selected="false">
                    ${preview}
                    <span class="td-picker-name" title="${escapeAttr(item.original_filename || '')}">${escapeHtml(item.original_filename || '')}</span>
                </button>
            `;
        }).join('');

        grid.querySelectorAll('.td-picker-item').forEach((btn) => {
            btn.addEventListener('click', () => {
                selected = items.find((it) => it.id === btn.dataset.id) || null;
                grid.querySelectorAll('.td-picker-item').forEach((el) => {
                    const on = el === btn;
                    el.classList.toggle('is-selected', on);
                    el.setAttribute('aria-selected', on ? 'true' : 'false');
                });
                confirm.disabled = !selected;
            });
            btn.addEventListener('dblclick', () => {
                if (selected) finish();
            });
        });
    }

    async function load(query = '') {
        status.textContent = 'Loading…';
        try {
            const data = await listMedia({ q: query, type: accept });
            items = data.items || [];
            selected = null;
            confirm.disabled = true;
            status.textContent = `${data.total} item${data.total === 1 ? '' : 's'}`;
            renderGrid();
        } catch (err) {
            status.textContent = err.message;
        }
    }

    function finish() {
        if (!selected) return;
        try { onSelect && onSelect(selected); }
        finally { close(); }
    }
    confirm.addEventListener('click', finish);

    search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => load(search.value.trim()), 250);
    });

    uploadIn.addEventListener('change', async (e) => {
        const file = e.target.files?.[0];
        e.target.value = '';
        if (!file) return;
        status.textContent = `Uploading ${file.name}…`;
        try {
            const media = await uploadFile(file);
            items.unshift(media);
            selected = media;
            renderGrid();
            // Mark the freshly-uploaded item selected so a single click on
            // "Insert" finishes the flow.
            const first = grid.querySelector('.td-picker-item');
            if (first) {
                first.classList.add('is-selected');
                first.setAttribute('aria-selected', 'true');
            }
            confirm.disabled = false;
            status.textContent = 'Uploaded.';
        } catch (err) {
            status.textContent = err.message;
        }
    });

    load();
}

/* ---------- Small helpers ---------- */

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
function escapeAttr(s) { return escapeHtml(s); }

window.TypeDockMedia = { openPicker, attachDropZone, uploadFile, listMedia };
