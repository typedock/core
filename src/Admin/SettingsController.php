<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class SettingsController extends BaseAdminController
{
    public function general(): void
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->query(
            "SELECT id, title FROM pages WHERE page_type = 'page' AND status = 'published' ORDER BY title ASC"
        );
        $pages = $stmt !== false ? $stmt->fetchAll() : [];

        $this->render('pages/settings/general.latte', [
            'options'       => $this->getOptions('general'),
            'pages'         => $pages,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function updateGeneral(): void
    {
        $this->setOption('site.name', $_POST['site_name'] ?? '', 'general');
        $this->setOption('site.description', $_POST['site_description'] ?? '', 'general');
        $this->setOption('scripts.head', $_POST['scripts_head'] ?? '', 'general');
        $this->setOption('scripts.body', $_POST['scripts_body'] ?? '', 'general');

        // Home page + posts archive settings.
        $homeMode   = $_POST['home_mode'] ?? 'archive';
        $homeMode   = in_array($homeMode, ['archive', 'page'], true) ? $homeMode : 'archive';
        $homePageId = trim((string) ($_POST['home_page_id'] ?? ''));
        if ($homeMode !== 'page') {
            $homePageId = '';
        }

        // Sanitise the posts archive slug: strip slashes, whitespace and any
        // character that would break a URL path segment. Fall back to 'blog'
        // if the operator clears it entirely.
        $postsSlug = trim((string) ($_POST['posts_archive_slug'] ?? 'blog'), "/ \t\n\r\0\x0B");
        $postsSlug = preg_replace('/[^A-Za-z0-9\-_]/', '', $postsSlug) ?? '';

        // Reserved slugs that would collide with hard-coded routes. Falling
        // back to 'blog' instead of bouncing the submission keeps the save
        // lenient — the UI hint warns about this separately.
        $reserved = ['admin', 'api', 'search', 'category', 'tag', 'sitemap.xml', 'feed', 'robots.txt', 'page', 'install.php'];
        if ($postsSlug === '' || in_array(strtolower($postsSlug), $reserved, true)) {
            $postsSlug = 'blog';
        }

        $postsLabel = trim((string) ($_POST['posts_archive_label'] ?? 'Blog'));
        if ($postsLabel === '') {
            $postsLabel = 'Blog';
        }

        $this->setOption('site.home_mode', $homeMode, 'general');
        $this->setOption('site.home_page_id', $homePageId !== '' ? $homePageId : null, 'general');
        $this->setOption('site.posts_archive_slug', $postsSlug, 'general');
        $this->setOption('site.posts_archive_label', $postsLabel, 'general');

        $this->redirect('/admin/settings/general', 'Settings saved successfully.');
    }

    public function seo(): void
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare("SELECT * FROM seo_meta WHERE target_type = 'global' AND target_id IS NULL LIMIT 1");
        $stmt->execute();
        $seo = $stmt->fetch() ?: [];
        $this->render('pages/settings/seo.latte', [
            'seo'           => $seo,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function updateSeo(): void
    {
        $seoService = new \TypeDock\Seo\SeoService(\Flight::db());
        $seoService->upsert('global', null, [
            'seo_title'        => $_POST['seo_title'] ?? null,
            'meta_description' => $_POST['meta_description'] ?? null,
            'robots'           => $_POST['robots'] ?? null,
        ]);
        $this->redirect('/admin/settings/seo', 'SEO settings saved successfully.');
    }

    public function modules(): void
    {
        $this->render('pages/settings/modules.latte', [
            'modules'        => config('modules.modules', []),
            'plugins'        => $this->discoverAllPlugins(),
            'plugin_issues'  => \Flight::plugin_diagnostics()->all(),
            'flash_success'  => $this->getFlash('success'),
            'flash_error'    => $this->getFlash('error'),
        ]);
    }

    /**
     * Toggle a plugin's enabled flag in site_options. DB state overrides the
     * env/config fallback chain in PluginLoader.
     */
    public function togglePlugin(): void
    {
        $slug    = trim((string) ($_POST['slug'] ?? ''));
        $enabled = !empty($_POST['enabled']);

        if ($slug === '' || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1) {
            $this->redirect('/admin/settings/modules', 'Invalid plugin slug.', 'error');
            return;
        }

        $key = 'plugin.' . $slug . '.enabled';
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $pdo = \Flight::db();

        $stmt = $pdo->prepare('SELECT 1 FROM site_options WHERE key_name = ?');
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() !== false) {
            $pdo->prepare('UPDATE site_options SET value = ?, group_name = ?, updated_at = ? WHERE key_name = ?')
                ->execute([json_encode($enabled), 'plugins', $now, $key]);
        } else {
            $pdo->prepare('INSERT INTO site_options (key_name, value, group_name, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$key, json_encode($enabled), 'plugins', $now]);
        }

        $this->redirect(
            '/admin/settings/modules',
            sprintf('%s %s.', ucfirst($slug), $enabled ? 'enabled' : 'disabled')
        );
    }

    /**
     * Build a unified list of all plugins — legacy built-ins from src/Plugin/ plus
     * drop-ins from plugins/<slug>/ — annotated with their current runtime
     * enabled state and the source of that state (DB / env / default).
     *
     * @return array<int, array{slug:string,name:string,version:string,source:string,enabled:bool,state_source:string}>
     */
    private function discoverAllPlugins(): array
    {
        $rows = [];

        // Legacy built-in plugins (hardcoded in PluginLoader::$builtInPlugins).
        $builtIns = [
            'AdvancedBlocks' => \TypeDock\Plugin\AdvancedBlocks\AdvancedBlocksPlugin::class,
        ];
        foreach ($builtIns as $name => $class) {
            $slug = strtolower($name);
            $rows[$slug] = [
                'slug'         => $slug,
                'name'         => class_exists($class) ? (new $class())->getName() : $name,
                'version'      => class_exists($class) ? (new $class())->getVersion() : '—',
                'source'       => 'built-in',
                'enabled'      => $this->resolveEnabled($slug, $name),
                'state_source' => $this->stateSource($slug),
            ];
        }

        // Drop-in plugins under plugins/<slug>/.
        foreach (glob(TYPEDOCK_ROOT . '/plugins/*/plugin.json') ?: [] as $manifestPath) {
            $slug     = basename(dirname($manifestPath));
            $manifest = json_decode((string) @file_get_contents($manifestPath), true);
            if (!is_array($manifest)) {
                continue;
            }
            $rows[$slug] = [
                'slug'         => $slug,
                'name'         => (string) ($manifest['name'] ?? ucfirst($slug)),
                'version'      => (string) ($manifest['version'] ?? '—'),
                'source'       => 'drop-in',
                'enabled'      => $this->resolveEnabled($slug, $this->configKeyForSlug($slug)),
                'state_source' => $this->stateSource($slug),
            ];
        }

        ksort($rows);
        return array_values($rows);
    }

    private function resolveEnabled(string $slug, string $configKey): bool
    {
        $db = $this->readDbPluginFlag($slug);
        if ($db !== null) {
            return $db;
        }
        $plugins = (array) config('modules.plugins', []);
        if (array_key_exists($configKey, $plugins)) {
            return (bool) $plugins[$configKey];
        }
        return (bool) env('PLUGIN_' . strtoupper(str_replace('-', '_', $slug)), false);
    }

    private function stateSource(string $slug): string
    {
        return $this->readDbPluginFlag($slug) !== null ? 'admin UI' : 'env/default';
    }

    private function configKeyForSlug(string $slug): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
    }

    private function readDbPluginFlag(string $slug): ?bool
    {
        try {
            $stmt = \Flight::db()->prepare('SELECT value FROM site_options WHERE key_name = ?');
            $stmt->execute(['plugin.' . $slug . '.enabled']);
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }
            $decoded = json_decode((string) $row['value'], true);
            return is_bool($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function getOptions(string $group): array
    {
        try {
            $stmt = \Flight::db()->prepare("SELECT key_name, value FROM site_options WHERE group_name = ?");
            $stmt->execute([$group]);
            $rows = $stmt->fetchAll();
            $opts = [];
            foreach ($rows as $row) {
                $opts[$row['key_name']] = json_decode((string) $row['value'], true);
            }
            return $opts;
        } catch (\Throwable) {
            return [];
        }
    }

    private function setOption(string $key, mixed $value, string $group): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $pdo = \Flight::db();

        $stmt = $pdo->prepare("SELECT key_name FROM site_options WHERE key_name = ? LIMIT 1");
        $stmt->execute([$key]);

        if ($stmt->fetch() !== false) {
            $pdo->prepare("UPDATE site_options SET value = ?, updated_at = ? WHERE key_name = ?")
                ->execute([json_encode($value), $now, $key]);
        } else {
            $pdo->prepare("INSERT INTO site_options (key_name, value, group_name, updated_at) VALUES (?, ?, ?, ?)")
                ->execute([$key, json_encode($value), $group, $now]);
        }
    }
}
