// TypeDock Admin JS (minimal)
document.addEventListener('DOMContentLoaded', function () {
    // Copy URL buttons
    document.querySelectorAll('.copy-url-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var url = btn.dataset.url;
            navigator.clipboard.writeText(url).then(function () {
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = 'Copy URL'; }, 2000);
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
});
