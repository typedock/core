<?php
declare(strict_types=1);

namespace TypeDock\Theme;

/**
 * Decide which Latte template to render for a given front-end request.
 *
 * All candidate paths are relative to the active theme root and resolved
 * against the filesystem so LatteFactory can load them directly via its
 * theme-dir include path.
 */
class TemplateResolver
{
    public function __construct(
        private readonly string $themesDir,
        private readonly string $activeTheme,
    ) {}

    /**
     * Resolve for a single post (page_type = 'post').
     *
     * @param array<array<string, mixed>> $categories  Category rows attached
     *                                                 to the post (ordered as
     *                                                 stored; the first is
     *                                                 treated as primary).
     * @param array<string, mixed>        $page        Page row.
     */
    public function resolvePost(array $page, array $categories = []): string
    {
        $candidates = [];

        $layout = $this->sanitize((string) ($page['layout'] ?? ''));
        if ($layout !== '') {
            $candidates[] = 'layouts/' . $layout . '.latte';
        }

        foreach ($categories as $cat) {
            $slug = $this->sanitize((string) ($cat['slug'] ?? ''));
            if ($slug !== '') {
                $candidates[] = 'single-' . $slug . '.latte';
            }
        }

        $candidates[] = 'single.latte';
        $candidates[] = 'layouts/single.latte';
        $candidates[] = 'layouts/base.latte';

        return $this->firstExisting($candidates, 'layouts/base.latte');
    }

    /**
     * Resolve for a static page (page_type = 'page').
     *
     * @param array<string, mixed> $page Page row.
     */
    public function resolvePage(array $page): string
    {
        $candidates = [];

        $layout = $this->sanitize((string) ($page['layout'] ?? ''));
        if ($layout !== '') {
            $candidates[] = 'layouts/' . $layout . '.latte';
        }

        $slug = $this->sanitize((string) ($page['slug'] ?? ''));
        if ($slug !== '') {
            $candidates[] = 'page-' . $slug . '.latte';
        }

        $candidates[] = 'page.latte';
        $candidates[] = 'layouts/page.latte';
        $candidates[] = 'layouts/base.latte';

        return $this->firstExisting($candidates, 'layouts/base.latte');
    }

    /**
     * Resolve for category / tag / blog archives.
     *
     * @param array<string, mixed>|null $term       Category or tag row, if any.
     * @param string                    $termType   'category' | 'tag' | ''.
     * @param bool                      $isHome     True when the request URL is `/`
     *                                              and the site is in archive-home mode.
     *                                              Bumps `home.latte` above `archive.latte`
     *                                              so themes can add hero bands etc.
     */
    public function resolveArchive(?array $term, string $termType = '', bool $isHome = false): string
    {
        $candidates = [];

        if ($isHome) {
            $candidates[] = 'home.latte';
            $candidates[] = 'layouts/home.latte';
        }

        if ($term !== null) {
            $slug = $this->sanitize((string) ($term['slug'] ?? ''));
            if ($slug !== '' && $termType !== '') {
                $candidates[] = 'archive-' . $termType . '-' . $slug . '.latte';
                $candidates[] = 'archive-' . $termType . '.latte';
            }
        }

        $candidates[] = 'archive.latte';
        $candidates[] = 'layouts/archive.latte';
        $candidates[] = 'layouts/base.latte';

        return $this->firstExisting($candidates, 'layouts/archive.latte');
    }

    /**
     * Resolve for the site root when home_mode is 'page'. A theme can ship a
     * `home.latte` to get a hero / featured layout; otherwise we fall back to
     * the same chain as `resolvePage()` so an operator-picked static page
     * just renders normally.
     *
     * @param array<string, mixed> $page
     */
    public function resolveHome(array $page): string
    {
        $candidates = [
            'home.latte',
            'layouts/home.latte',
        ];

        $slug = $this->sanitize((string) ($page['slug'] ?? ''));
        if ($slug !== '') {
            $candidates[] = 'page-' . $slug . '.latte';
        }

        $candidates[] = 'page.latte';
        $candidates[] = 'layouts/page.latte';
        $candidates[] = 'layouts/base.latte';

        return $this->firstExisting($candidates, 'layouts/page.latte');
    }

    /**
     * @param array<string> $candidates  Theme-relative paths to try in order.
     */
    private function firstExisting(array $candidates, string $fallback): string
    {
        $root = $this->themesDir . '/' . $this->activeTheme . '/';
        foreach ($candidates as $rel) {
            if (is_file($root . $rel)) {
                return $rel;
            }
        }
        return $fallback;
    }

    /**
     * Prevent path traversal / unexpected characters leaking from the
     * database into filesystem lookups.
     */
    private function sanitize(string $value): string
    {
        if ($value === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $value)) {
            return '';
        }
        return $value;
    }
}
