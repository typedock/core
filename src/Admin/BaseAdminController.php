<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Middleware\CsrfMiddleware;

abstract class BaseAdminController
{
    /**
     * @param array<string, mixed> $params
     */
    protected function render(string $template, array $params = []): void
    {
        $user    = \Flight::get('current_user');
        $siteObj = $this->buildSiteObject();

        $defaults = [
            'current_user' => $user,
            'site'         => $siteObj,
            'csrf_token'   => CsrfMiddleware::generate(),
            'current_path' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
        ];

        \Flight::latte()->render($template, array_merge($defaults, $params), TYPEDOCK_ROOT . '/admin');
    }

    protected function redirect(string $path, string $message = '', string $type = 'success'): void
    {
        if ($message !== '') {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['flash_' . $type] = $message;
        }
        \Flight::redirect($path);
        exit;
    }

    protected function getFlash(string $type = 'success'): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $key = 'flash_' . $type;
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }

    /**
     * Compute the public-facing URL for a content row.
     *
     * Posts live under the configured posts archive slug (default `/blog`);
     * pages live at `/{slug}`. Returns null when the row has no slug (not
     * yet persisted) or a type we don't know how to route. The edit view
     * uses this to expose a "View" link in the Publish panel — we only
     * render the link itself when the row is actually published, but
     * computing the URL is cheap regardless.
     *
     * @param array<string, mixed>|null $page
     */
    protected function publicUrlFor(?array $page): ?string
    {
        if ($page === null) {
            return null;
        }
        $slug = (string) ($page['slug'] ?? '');
        if ($slug === '') {
            return null;
        }
        return match ($page['page_type'] ?? null) {
            'post' => post_path($slug),
            'page' => '/' . $slug,
            default => null,
        };
    }

    /**
     * Build the flash message shown after a successful save. When the row
     * was just published, we append the public URL so the editor can copy
     * it from the flash bar even before looking at the sidebar.
     *
     * @param array<string, mixed>|null $page
     */
    protected function saveMessage(string $noun, ?array $page): string
    {
        if (($page['status'] ?? null) === 'published') {
            $url = $this->publicUrlFor($page);
            return $url !== null
                ? "{$noun} published. View: {$url}"
                : "{$noun} published.";
        }
        return "{$noun} saved.";
    }

    /**
     * Pull the per-post SEO form submission out of $_POST['seo'].
     *
     * Whitespace-only inputs are normalised to null so inheritance from
     * the global defaults still works — the SEO edit form's contract is
     * "blank = inherit", not "blank = force empty override".
     *
     * @return array<string, string|null>
     */
    protected function collectSeoInput(): array
    {
        $raw = $_POST['seo'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $keys = [
            'seo_title', 'meta_description', 'canonical_url', 'robots',
            'og_title', 'og_description', 'og_image_id', 'twitter_card',
            'focus_keyword', 'schema_type',
        ];
        $out = [];
        foreach ($keys as $k) {
            $v = isset($raw[$k]) ? trim((string) $raw[$k]) : '';
            $out[$k] = $v === '' ? null : $v;
        }
        return $out;
    }

    /**
     * Read the active theme's layout declarations from theme.json. Used by
     * the post/page editor to populate the Layout dropdown.
     *
     * @return array<string, array<string, mixed>>  Keyed by layout file stem
     *                                              (without `.latte`).
     */
    protected function themeLayouts(): array
    {
        try {
            $loader = new \TypeDock\Theme\ThemeLoader();
            $active = $loader->resolveActiveTheme(\Flight::db());
            $config = $loader->loadThemeConfig($active);
            $layouts = $config['layouts'] ?? [];
            return is_array($layouts) ? $layouts : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function buildSiteObject(): object
    {
        try {
            $stmt = \Flight::db()->prepare("SELECT key_name, value FROM site_options WHERE group_name = 'general'");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $opts = [];
            foreach ($rows as $row) {
                $opts[$row['key_name']] = json_decode((string) $row['value'], true);
            }
        } catch (\Throwable) {
            $opts = [];
        }

        return (object) [
            'name' => $opts['site.name'] ?? config('app.name', 'TypeDock'),
            'url'  => config('app.url', 'http://localhost'),
        ];
    }
}
