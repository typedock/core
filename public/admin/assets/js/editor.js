/*
 * TypeDock block editor (Tiptap-based)
 * ------------------------------------
 * Why CDN ES modules: TypeDock is PHP-first and ships without a Node/npm
 * build step. esm.sh transpiles npm packages into browser-native ESM and
 * resolves their dependency graph, giving us zero-build Tiptap.
 *
 * Why no `?bundle` on @tiptap/*: with `?bundle` each package ships its own
 * inlined copy of prosemirror-state/view/etc. When StarterKit and
 * @tiptap/core each carry their own `prosemirror-state`, the `history`
 * plugin's PluginKey is created twice from two different classes, and
 * ProseMirror throws "Adding different instances of a keyed plugin". Using
 * unbundled URLs forces every extension to import the *same* esm.sh module,
 * which the browser dedupes via URL identity.
 *
 * Why the double pin (`?deps=@tiptap/core@X,@tiptap/pm@X`): Tiptap v2
 * extensions declare peer deps on both `@tiptap/core` (for Editor/Node APIs)
 * and `@tiptap/pm` (their prosemirror-* shim). Without pinning both, esm.sh
 * may resolve a transitive extension (e.g. extension-horizontal-rule pulled
 * in by starter-kit) to a *newer* version that expects core exports which
 * don't exist in an older pinned core — producing SyntaxError about a
 * missing named export (e.g. `canInsertNode`). Pinning both peer deps forces
 * the whole extension graph onto one coherent Tiptap release.
 *
 * Storage contract:
 *   - Persistence format = Markdown (matches BlockRenderer fallback,
 *     which treats a non-JSON body string as plain Markdown via
 *     League\CommonMark with the ==mark== and [card:/path] extensions).
 *   - On load:  Markdown -> HTML via `marked`, then Tiptap renders it.
 *   - On save:  Tiptap HTML -> Markdown via `turndown`, written into the
 *     hidden <textarea name="body"> right before form submit.
 *
 * Custom inline syntax preservation:
 *   - `==mark==` round-trips via a Turndown rule that emits `==text==` for
 *     <mark> nodes, plus a marked extension that converts ==x== back to
 *     <mark>x</mark> on load.
 *   - `[card:/path]` is preserved verbatim. We protect it on load (so marked
 *     does not eat the brackets) and re-emit it on save via a Turndown rule
 *     against our placeholder span.
 *
 * Raw mode: a toggle swaps between Tiptap and a plain <textarea> bound to
 * the same Markdown string, for power users / debugging.
 */

import { Editor } from 'https://esm.sh/@tiptap/core@2.27.2?deps=@tiptap/pm@2.27.2';
import StarterKit from 'https://esm.sh/@tiptap/starter-kit@2.27.2?deps=@tiptap/core@2.27.2,@tiptap/pm@2.27.2';
import Link from 'https://esm.sh/@tiptap/extension-link@2.27.2?deps=@tiptap/core@2.27.2,@tiptap/pm@2.27.2';
import Image from 'https://esm.sh/@tiptap/extension-image@2.27.2?deps=@tiptap/core@2.27.2,@tiptap/pm@2.27.2';
import { marked } from 'https://esm.sh/marked@13.0.3';
import TurndownService from 'https://esm.sh/turndown@7.2.0';

const mountEl = document.getElementById('editor');
const toolbarEl = document.getElementById('editor-toolbar');
const rawEl = document.getElementById('editor-raw');
const bodyField = document.getElementById('body-field');
const form = document.getElementById('post-form');

// Sentinels used to protect `[card:/path]` tokens from marked during
// Markdown -> HTML conversion. Declared here (before initEditor runs) so
// they are not in the temporal dead zone when mdToHtml is first called.
const CARD_PLACEHOLDER_OPEN = '\u0000CARD\u0000';
const CARD_PLACEHOLDER_CLOSE = '\u0000/CARD\u0000';

if (mountEl && toolbarEl && bodyField && form) {
    initEditor();
}

function initEditor() {
    const initialMarkdown = mountEl.dataset.initial || bodyField.value || '';

    // ---- marked: configure + register ==mark== inline extension ----
    marked.use({
        extensions: [{
            name: 'mark',
            level: 'inline',
            start(src) { return src.indexOf('=='); },
            tokenizer(src) {
                const m = /^==([^=]+?)==/.exec(src);
                if (m) {
                    return { type: 'mark', raw: m[0], text: m[1] };
                }
            },
            renderer(token) {
                return `<mark>${escapeHtml(token.text)}</mark>`;
            },
        }],
    });

    // ---- Turndown: configure ----
    const turndown = new TurndownService({
        headingStyle: 'atx',
        codeBlockStyle: 'fenced',
        bulletListMarker: '-',
    });
    // <mark> -> ==text==
    turndown.addRule('mark', {
        filter: 'mark',
        replacement: (content) => `==${content}==`,
    });
    // Card placeholder span -> [card:/path]
    turndown.addRule('cardLink', {
        filter: (node) => node.nodeName === 'SPAN' && node.classList?.contains('td-card'),
        replacement: (_c, node) => `[card:${node.getAttribute('data-path') || ''}]`,
    });

    const initialHtml = mdToHtml(initialMarkdown);

    const editor = new Editor({
        element: mountEl,
        extensions: [
            StarterKit.configure({ heading: { levels: [2, 3, 4] } }),
            Link.configure({ openOnClick: false, autolink: true }),
            Image,
        ],
        content: initialHtml,
        onUpdate: () => syncToHidden(editor, turndown),
    });

    buildToolbar(toolbarEl, editor);
    syncToHidden(editor, turndown);

    // Raw-mode toggle
    let rawMode = false;
    const rawBtn = makeBtn('Raw MD', () => {
        rawMode = !rawMode;
        if (rawMode) {
            rawEl.value = bodyField.value;
            rawEl.hidden = false;
            mountEl.style.display = 'none';
            toolbarEl.style.opacity = '0.4';
            toolbarEl.querySelectorAll('button').forEach((b) => {
                if (b !== rawBtn) b.disabled = true;
            });
        } else {
            // Pull edits back from raw textarea -> editor
            bodyField.value = rawEl.value;
            editor.commands.setContent(mdToHtml(rawEl.value), false);
            rawEl.hidden = true;
            mountEl.style.display = '';
            toolbarEl.style.opacity = '';
            toolbarEl.querySelectorAll('button').forEach((b) => { b.disabled = false; });
        }
        rawBtn.classList.toggle('is-active', rawMode);
    });
    rawBtn.classList.add('toolbar-spacer-left');
    toolbarEl.appendChild(rawBtn);

    rawEl.addEventListener('input', () => { bodyField.value = rawEl.value; });

    form.addEventListener('submit', () => {
        if (rawMode) {
            bodyField.value = rawEl.value;
        } else {
            syncToHidden(editor, turndown);
        }
    });
}

function syncToHidden(editor, turndown) {
    const html = editor.getHTML();
    bodyField.value = htmlToMd(html, turndown);
}

/* ---------- Markdown <-> HTML helpers ---------- */

function mdToHtml(md) {
    if (!md) return '';
    // Protect [card:/path] from marked by swapping to a sentinel, then to a span.
    const protectedMd = md.replace(/\[card:([^\]]+)\]/g,
        (_m, p) => `${CARD_PLACEHOLDER_OPEN}${p}${CARD_PLACEHOLDER_CLOSE}`);
    let html = marked.parse(protectedMd, { gfm: true, breaks: false });
    html = html.replaceAll(
        new RegExp(`${CARD_PLACEHOLDER_OPEN}([^\u0000]+)${CARD_PLACEHOLDER_CLOSE}`, 'g'),
        (_m, p) => `<span class="td-card" data-path="${escapeAttr(p)}">[card:${escapeHtml(p)}]</span>`
    );
    return html;
}

function htmlToMd(html, turndown) {
    return turndown.turndown(html || '').trim();
}

function escapeHtml(s) {
    return s.replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
function escapeAttr(s) { return escapeHtml(s); }

/* ---------- Toolbar ---------- */

function buildToolbar(root, editor) {
    const items = [
        ['B',     'Bold',         () => editor.chain().focus().toggleBold().run(),                 () => editor.isActive('bold')],
        ['I',     'Italic',       () => editor.chain().focus().toggleItalic().run(),               () => editor.isActive('italic')],
        ['H2',    'Heading 2',    () => editor.chain().focus().toggleHeading({ level: 2 }).run(),  () => editor.isActive('heading', { level: 2 })],
        ['H3',    'Heading 3',    () => editor.chain().focus().toggleHeading({ level: 3 }).run(),  () => editor.isActive('heading', { level: 3 })],
        ['UL',    'Bullet list',  () => editor.chain().focus().toggleBulletList().run(),           () => editor.isActive('bulletList')],
        ['OL',    'Ordered list', () => editor.chain().focus().toggleOrderedList().run(),          () => editor.isActive('orderedList')],
        ['"',     'Blockquote',   () => editor.chain().focus().toggleBlockquote().run(),           () => editor.isActive('blockquote')],
        ['</>',   'Code',         () => editor.chain().focus().toggleCode().run(),                 () => editor.isActive('code')],
        ['Link',  'Insert link',  () => {
            const prev = editor.getAttributes('link').href || '';
            const url = window.prompt('URL', prev);
            if (url === null) return;
            if (url === '') {
                editor.chain().focus().extendMarkRange('link').unsetLink().run();
            } else {
                editor.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
            }
        }, () => editor.isActive('link')],
        ['Img',   'Insert image from Media library', () => {
            // Defer to the shared picker (public/admin/assets/js/media-picker.js).
            // The picker is loaded as a classic script on edit.latte and exposes
            // itself on window.TypeDockMedia. When it's not loaded for any reason
            // we fall back to a URL prompt rather than silently failing.
            const picker = window.TypeDockMedia;
            if (!picker || typeof picker.openPicker !== 'function') {
                const url = window.prompt('Image URL');
                if (url) editor.chain().focus().setImage({ src: url }).run();
                return;
            }
            picker.openPicker({
                accept: 'image',
                onSelect: (media) => {
                    editor.chain().focus().setImage({
                        src: media.url,
                        alt: media.alt_text || '',
                        title: media.original_filename || '',
                    }).run();
                },
            });
        }],
    ];

    const buttons = items.map(([label, title, onClick, isActive]) => {
        const btn = makeBtn(label, onClick, title);
        if (isActive) {
            editor.on('selectionUpdate', () => btn.classList.toggle('is-active', !!isActive()));
            editor.on('transaction',     () => btn.classList.toggle('is-active', !!isActive()));
        }
        root.appendChild(btn);
        return btn;
    });
    return buttons;
}

function makeBtn(label, onClick, title) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'toolbar-btn';
    btn.textContent = label;
    if (title) btn.title = title;
    btn.addEventListener('click', (e) => { e.preventDefault(); onClick(); });
    return btn;
}
