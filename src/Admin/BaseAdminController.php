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
            'current_user'      => $user,
            'site'              => $siteObj,
            'csrf_token'        => CsrfMiddleware::generate(),
            'current_path'      => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'admin_locale'      => \Flight::admin_locale(),
            'admin_locales'     => \Flight::admin_locale_resolver()->locales(),
            'plugin_admin_menu' => \Flight::plugin_admin_menu()->all(),
            'editor_extension_scripts' => \Flight::editor_extensions()->scripts(),
            'editor_asset_version' => $this->editorAssetVersion(),
        ];

        \Flight::latte()->render($template, array_merge($defaults, $params), TYPEDOCK_ROOT . '/admin');
    }

    protected function can(string $permission): bool
    {
        $user = \Flight::get('current_user');
        if (!is_array($user)) {
            return false;
        }
        return \Flight::permissions()->can($user, $permission);
    }

    /**
     * Strip component blocks from a Tiptap body that the current user is not
     * authorised to insert (e.g. `custom_html` for non-editor roles). Sets a
     * flash warning when something was removed so the saver isn't silently
     * surprised. Returns the filtered body in the same shape it came in.
     */
    protected function filterUnsafeBlocks(string|array|null $body): string|array|null
    {
        try {
            $registry = \Flight::components();
        } catch (\Throwable) {
            return $body;
        }
        $filter = new \TypeDock\Content\UnsafeBlockFilter(
            $registry,
            fn(string $perm): bool => $this->can($perm),
        );
        $out = $filter->filter($body);
        $removed = $filter->getRemoved();
        if ($removed !== []) {
            typedock_session_start();
            $_SESSION['flash_error'] = sprintf(
                __('Removed %d block(s) you are not allowed to publish: %s. '
                . 'Ask an editor to add raw HTML on your behalf.'),
                count($removed),
                implode(', ', $removed),
            );
        }
        return $out;
    }

    /**
     * Authorize access to an owned row. Grants when the caller is the owner
     * and has $ownPermission, or has $anyPermission regardless of ownership.
     * Throws ForbiddenException otherwise.
     *
     * @param array<string, mixed> $row
     */
    protected function authorizeOwnerOrAny(array $row, string $ownPermission, string $anyPermission, string $ownerColumn = 'author_id'): void
    {
        $user = \Flight::get('current_user');
        if (!is_array($user)) {
            throw new \TypeDock\Exception\ForbiddenException('Not authenticated');
        }
        $perms   = \Flight::permissions();
        $isOwner = ($row[$ownerColumn] ?? null) !== null
            && (string) $row[$ownerColumn] === (string) ($user['id'] ?? '');

        if ($isOwner && $perms->can($user, $ownPermission)) {
            return;
        }
        if ($perms->can($user, $anyPermission)) {
            return;
        }
        throw new \TypeDock\Exception\ForbiddenException('Insufficient permissions');
    }

    /**
     * If the caller lacks $publishPermission, downgrade any publish intent to
     * draft. Keeps contributor/author submissions usable without surfacing a
     * 403 for a common UI mistake.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function downgradeIfCannotPublish(array $data, string $publishPermission): array
    {
        if (($data['status'] ?? null) === 'published' && !$this->can($publishPermission)) {
            $data['status'] = 'draft';
        }
        return $data;
    }

    protected function redirect(string $path, string $message = '', string $type = 'success'): void
    {
        if ($message !== '') {
            typedock_session_start();
            $_SESSION['flash_' . $type] = $message;
        }
        \Flight::redirect($path);
        exit;
    }

    protected function getFlash(string $type = 'success'): ?string
    {
        typedock_session_start();
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
        return match ($page['post_type'] ?? null) {
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
        $nounLabel = __($noun);
        if (($page['status'] ?? null) === 'published') {
            $url = $this->publicUrlFor($page);
            return $url !== null
                ? __('{noun} published. View: {url}', ['noun' => $nounLabel, 'url' => $url])
                : __('{noun} published.', ['noun' => $nounLabel]);
        }
        return __('{noun} saved.', ['noun' => $nounLabel]);
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
     * Build the block-editor's component catalog (used for the slash menu
     * and the component-block parameter modal). Shipped as a JSON literal
     * via `window.typedockComponentDefs`. Only components with `block` in
     * their `placeable` list are included — slot-only components don't
     * belong inside page body content.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function editorComponentDefs(): array
    {
        try {
            $registry = \Flight::components();
            $optionsResolver = new \TypeDock\Component\ParamOptionsResolver();
            $defs     = [];
            foreach ($registry->list() as $type => $def) {
                if (!empty($def->placeable) && !in_array('block', $def->placeable, true)) {
                    continue;
                }
                // Hide capability-gated components (e.g. custom_html) from the
                // slash menu when the caller can't use them. This is a UX
                // courtesy — the server-side UnsafeBlockFilter is what actually
                // enforces the rule on save.
                if ($def->requiresCapability !== null && !$this->can($def->requiresCapability)) {
                    continue;
                }
                $def = $optionsResolver->enrich($def);
                $defaults = [];
                foreach ($def->params as $p) {
                    if (isset($p['name'])) {
                        $defaults[$p['name']] = $p['default'] ?? null;
                    }
                }
                $defs[$type] = [
                    'name'          => $def->name,
                    'icon'          => $def->icon,
                    'placeable'     => $def->placeable,
                    'params'        => $def->params,
                    'defaultParams' => $defaults,
                ];
            }
            return $defs;
        } catch (\Throwable) {
            return [];
        }
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

    private function editorAssetVersion(): int
    {
        $files = [
            TYPEDOCK_ROOT . '/public/admin/dist/editor.bundle.js',
            TYPEDOCK_ROOT . '/public/admin/dist/editor.bundle.css',
        ];
        $versions = array_map(
            static fn(string $file): int => is_file($file) ? (int) filemtime($file) : 0,
            $files
        );
        return max($versions) ?: time();
    }
}
