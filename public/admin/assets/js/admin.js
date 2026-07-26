// TypeDock Admin JS (minimal)
document.addEventListener('DOMContentLoaded', function () {
    // Copy-to-clipboard buttons. We reuse the same class for "Copy URL" and
    // "Copy ID" so the label on restore comes from the button itself, not a
    // hardcoded string.
    document.querySelectorAll('.copy-url-btn').forEach(function (btn) {
        var originalLabel = btn.textContent;
        btn.addEventListener('click', function () {
            navigator.clipboard.writeText(btn.dataset.url).then(function () {
                var uploadArea = document.getElementById('media-upload-area');
                btn.textContent = btn.dataset.copiedLabel
                    || (uploadArea && uploadArea.dataset.copiedLabel)
                    || 'Copied';
                setTimeout(function () { btn.textContent = originalLabel; }, 2000);
            });
        });
    });

    // Auto-slug from title
    var titleInput = document.getElementById('post-title');
    var slugInput  = document.getElementById('post-slug');
    if (titleInput && slugInput && slugInput.value === '') {
        titleInput.addEventListener('input', function () {
            slugInput.value = titleInput.value
                .toLowerCase()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .substring(0, 100);
        });
    }

    // Tabs — public API for plugin admin pages.
    //
    //   <div data-tabs>
    //     <div class="tabs">
    //       <button class="tab is-active" data-tab-target="general">General</button>
    //       <button class="tab"           data-tab-target="advanced">Advanced</button>
    //     </div>
    //     <div class="tab-panel is-active" data-tab-panel="general">…</div>
    //     <div class="tab-panel"           data-tab-panel="advanced">…</div>
    //   </div>
    //
    // Scoped to each [data-tabs] so multiple tab groups on one page don't
    // cross-talk. If [data-tab-remember="key"] is set, the last-active tab
    // is persisted in localStorage under that key.
    document.querySelectorAll('[data-tabs]').forEach(function (root) {
        var storageKey = root.getAttribute('data-tab-remember');
        var targets = root.querySelectorAll('[data-tab-target]');
        var panels  = root.querySelectorAll('[data-tab-panel]');

        function activate(name) {
            targets.forEach(function (t) {
                t.classList.toggle('is-active', t.getAttribute('data-tab-target') === name);
            });
            panels.forEach(function (p) {
                p.classList.toggle('is-active', p.getAttribute('data-tab-panel') === name);
            });
            if (storageKey) {
                try { localStorage.setItem('td:tab:' + storageKey, name); } catch (e) {}
            }
        }

        targets.forEach(function (t) {
            t.addEventListener('click', function (e) {
                if (t.tagName === 'A' && t.getAttribute('href')) return; // let links navigate
                e.preventDefault();
                activate(t.getAttribute('data-tab-target'));
            });
        });

        if (storageKey) {
            try {
                var saved = localStorage.getItem('td:tab:' + storageKey);
                if (saved) activate(saved);
            } catch (e) {}
        }
    });
});

// Background worker tick.
//
// On hosting without cron this is the only thing that moves the job queue, so
// every admin page load nudges the worker once — at most once a minute per
// tab. Sites running cron or a resident worker get this for free: the tick
// simply finds nothing left to do. Anonymous pages (login) carry no marker
// meta and never tick.
(function () {
    var endpoint = document.querySelector('meta[name="typedock-queue-tick"]');
    var token    = document.querySelector('meta[name="csrf-token"]');
    if (!endpoint || !token || !token.content) return;

    var KEY = 'typedock:queue-tick';
    var now = Date.now();
    try {
        if (now - Number(sessionStorage.getItem(KEY) || 0) < 60000) return;
        sessionStorage.setItem(KEY, String(now));
    } catch (e) {
        // Storage blocked (private mode): fall through and tick every load.
    }

    // After load, never before — the tick must not delay the page the user
    // actually asked for.
    window.addEventListener('load', function () {
        fetch(endpoint.content, {
            method: 'POST',
            headers: { 'X-CSRF-Token': token.content },
            credentials: 'same-origin',
            keepalive: true
        }).catch(function () {
            // Offline or session expired — the next page load tries again.
        });
    });
})();
