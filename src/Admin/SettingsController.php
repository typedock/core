<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use League\CommonMark\CommonMarkConverter;
use TypeDock\Auth\ApiKeyService;
use TypeDock\Core\AssetPublisher;

class SettingsController extends BaseAdminController
{
    public function general(): void
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare(
            "SELECT id, title FROM posts WHERE post_type = 'page' AND status = 'published' AND locale = ? ORDER BY title ASC"
        );
        $pages = [];
        if ($stmt !== false) {
            $stmt->execute([typedock_default_locale()]);
            $pages = $stmt->fetchAll();
        }
        try {
            $sources = \Flight::external_sources()->activeSources();
        } catch (\Throwable) {
            $sources = [];
        }

        $this->render('pages/settings/general.latte', [
            'options'       => $this->getOptions('general'),
            'site_locale'   => $this->siteLocaleStatus(),
            'pages'         => $pages,
            'external_sources' => $sources,
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
        $homeMode   = in_array($homeMode, ['archive', 'page', 'source'], true) ? $homeMode : 'archive';
        $homePageId = trim((string) ($_POST['home_page_id'] ?? ''));
        $homeSourceId = trim((string) ($_POST['home_source_id'] ?? ''));
        if ($homeMode !== 'page') {
            $homePageId = '';
        }
        if ($homeMode !== 'source') {
            $homeSourceId = '';
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
        $this->setOption('site.home_source_id', $homeSourceId !== '' ? $homeSourceId : null, 'general');
        $this->setOption('site.posts_archive_slug', $postsSlug, 'general');
        $this->setOption('site.posts_archive_label', $postsLabel, 'general');

        $this->redirect('/admin/settings/general', __('Settings saved successfully.'));
    }

    /**
     * @return array{current: string, default: string, source: string}
     */
    private function siteLocaleStatus(): array
    {
        $default = typedock_default_locale();
        $current = typedock_current_locale();
        $source = 'APP_LOCALE';

        try {
            $stmt = \Flight::db()->query('SELECT code FROM locales WHERE is_default = 1 LIMIT 1');
            $row = $stmt ? $stmt->fetch() : false;
            if ($row !== false && (string) ($row['code'] ?? '') !== '') {
                $source = 'locales.is_default';
            }
        } catch (\Throwable) {
            // The locales table may not exist during early install paths.
        }

        return [
            'current' => $current,
            'default' => $default,
            'source'  => $source,
        ];
    }

    public function seo(): void
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare("SELECT * FROM seo_meta WHERE target_type = 'global' AND target_id IS NULL LIMIT 1");
        $stmt->execute();
        $seo = $stmt->fetch() ?: [];
        $this->render('pages/settings/seo.latte', [
            'seo'           => $seo,
            'default_og_image' => $this->mediaPreview($seo['og_image_id'] ?? null),
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
            'og_image_id'      => trim((string) ($_POST['og_image_id'] ?? '')) ?: null,
            'twitter_card'     => $_POST['twitter_card'] ?? null,
        ]);
        $this->redirect('/admin/settings/seo', 'SEO settings saved successfully.');
    }

    public function mail(): void
    {
        $this->render('pages/settings/mail.latte', [
            'mail'          => $this->mailSettings(),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function updateMail(): void
    {
        $current = $this->mailSettings();
        $driver = (string) ($_POST['mail_default'] ?? 'php');
        if (!in_array($driver, ['php', 'sendmail', 'smtp'], true)) {
            $driver = 'php';
        }

        $password = (string) ($_POST['smtp_password'] ?? '');
        if ($password === '' && empty($_POST['clear_smtp_password'])) {
            $password = (string) ($current['smtp']['password'] ?? '');
        }

        $this->setOption('mail.default', $driver, 'mail');
        $this->setOption('mail.from_email', trim((string) ($_POST['from_email'] ?? '')), 'mail');
        $this->setOption('mail.from_name', trim((string) ($_POST['from_name'] ?? '')), 'mail');
        $this->setOption('mail.smtp.host', trim((string) ($_POST['smtp_host'] ?? '')), 'mail');
        $this->setOption('mail.smtp.port', max(1, min(65535, (int) ($_POST['smtp_port'] ?? 587))), 'mail');
        $this->setOption('mail.smtp.username', trim((string) ($_POST['smtp_username'] ?? '')), 'mail');
        $this->setOption('mail.smtp.password', $password, 'mail');
        $this->setOption('mail.smtp.encryption', $this->mailEncryption((string) ($_POST['smtp_encryption'] ?? 'tls')), 'mail');

        $this->redirect('/admin/settings/mail', 'Mail settings saved successfully.');
    }

    public function testMail(): void
    {
        $to = trim((string) ($_POST['test_to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->redirect('/admin/settings/mail', 'Enter a valid recipient email address.', 'error');
            return;
        }

        $ok = \Flight::mailer()->send(
            $to,
            'TypeDock test email',
            "This is a test email from TypeDock.\n\nIf you received this, your mail settings are working.",
            ['html' => false]
        );

        $this->redirect(
            '/admin/settings/mail',
            $ok ? 'Test email sent.' : 'Test email failed. Check SMTP host, credentials, and server logs.',
            $ok ? 'success' : 'error'
        );
    }

    public function apiKeys(): void
    {
        $user = \Flight::get('current_user');
        $apiKey = $this->consumeApiKeyFlash();

        $this->render('pages/settings/api.latte', [
            'api_enabled'    => (bool) site_option('api.enabled', config('app.api_enabled', false)),
            'api_env_locked' => (bool) config('app.api_enabled', false),
            'api_keys'       => $this->formatApiKeys(\Flight::apikey()->listByUser((string) ($user['id'] ?? ''))),
            'scopes'         => ApiKeyService::availableScopes(),
            'default_scopes' => ApiKeyService::defaultReadScopes(),
            'new_api_key'    => $apiKey,
            'flash_success'  => $this->getFlash('success'),
            'flash_error'    => $this->getFlash('error'),
        ]);
    }

    public function updateApiSettings(): void
    {
        $enabled = !empty($_POST['api_enabled']);
        $this->setOption('api.enabled', $enabled, 'api');
        $this->redirect('/admin/settings/api', $enabled ? 'API enabled.' : 'API disabled.');
    }

    public function createApiKey(): void
    {
        $user = \Flight::get('current_user');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $this->redirect('/admin/settings/api', 'API key name is required.', 'error');
            return;
        }

        $rawScopes = $_POST['scopes'] ?? [];
        $scopes = is_array($rawScopes) ? ApiKeyService::filterScopes($rawScopes) : [];
        $permissions = !empty($_POST['inherit_role']) ? null : $scopes;

        $expiresAt = null;
        $expiresIn = (int) ($_POST['expires_in_days'] ?? 0);
        if ($expiresIn > 0) {
            $expiresAt = (new \DateTimeImmutable())->modify('+' . min($expiresIn, 3650) . ' days');
        }

        $created = \Flight::apikey()->create((string) ($user['id'] ?? ''), $name, $permissions, $expiresAt);
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['new_api_key'] = $created['key'];
        $this->redirect('/admin/settings/api', 'API key created. Copy it now; it will not be shown again.');
    }

    public function revokeApiKey(): void
    {
        $id = trim((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            $this->redirect('/admin/settings/api', 'Missing API key id.', 'error');
            return;
        }

        \Flight::apikey()->revoke($id);
        $this->redirect('/admin/settings/api', 'API key revoked.');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mediaPreview(mixed $mediaId): ?array
    {
        $mediaId = is_string($mediaId) ? trim($mediaId) : '';
        if ($mediaId === '') {
            return null;
        }
        try {
            return \Flight::media_service()->find($mediaId);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{default:string,from_email:string,from_name:string,smtp:array{host:string,port:int,username:string,password:string,encryption:string}}
     */
    private function mailSettings(): array
    {
        return [
            'default' => (string) site_option('mail.default', config('mail.default', 'php')),
            'from_email' => (string) site_option('mail.from_email', config('mail.from_email', 'noreply@example.com')),
            'from_name' => (string) site_option('mail.from_name', config('mail.from_name', 'TypeDock')),
            'smtp' => [
                'host' => (string) site_option('mail.smtp.host', config('mail.smtp.host', 'localhost')),
                'port' => (int) site_option('mail.smtp.port', config('mail.smtp.port', 587)),
                'username' => (string) site_option('mail.smtp.username', config('mail.smtp.username', '')),
                'password' => (string) site_option('mail.smtp.password', config('mail.smtp.password', '')),
                'encryption' => (string) site_option('mail.smtp.encryption', config('mail.smtp.encryption', 'tls')),
            ],
        ];
    }

    private function mailEncryption(string $value): string
    {
        return in_array($value, ['', 'tls', 'ssl'], true) ? $value : 'tls';
    }

    private function consumeApiKeyFlash(): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $value = $_SESSION['new_api_key'] ?? null;
        unset($_SESSION['new_api_key']);
        return is_string($value) ? $value : null;
    }

    /**
     * @param array<array<string, mixed>> $keys
     * @return array<array<string, mixed>>
     */
    private function formatApiKeys(array $keys): array
    {
        foreach ($keys as &$key) {
            if ($key['permissions'] === null) {
                $key['scope_label'] = 'Role permissions';
                continue;
            }
            $decoded = json_decode((string) $key['permissions'], true);
            $key['scope_label'] = is_array($decoded) && $decoded !== []
                ? implode(', ', array_map('strval', $decoded))
                : 'No scopes';
        }
        unset($key);
        return $keys;
    }

    public function modules(): void
    {
        // Route is still `/admin/settings/modules` for URL stability, but the
        // page is plugins-only now that the Module concept has been retired.
        $this->render('pages/settings/modules.latte', [
            'modules'        => [],
            'plugins'        => $this->discoverAllPlugins(),
            'plugin_issues'  => \Flight::plugin_diagnostics()->all(),
            'flash_success'  => $this->getFlash('success'),
            'flash_error'    => $this->getFlash('error'),
        ]);
    }

    public function pluginDocs(string $slug): void
    {
        $doc = $this->pluginReadme($slug);
        if ($doc === null) {
            throw new \TypeDock\Exception\NotFoundException("Plugin documentation not found: {$slug}");
        }

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $this->render('pages/plugins/docs.latte', [
            'plugin' => $doc['plugin'],
            'html'   => (string) $converter->convert($doc['markdown']),
        ]);
    }

    /**
     * Install a plugin from an uploaded zip. The installer validates the
     * manifest (slug regex, main_class), rejects path traversal and any
     * PHP placed under `public/`, and overwrites only when the admin opts
     * in. Plugins are NEVER auto-enabled — admin still has to flip the
     * toggle on /admin/settings/modules.
     */
    public function uploadPlugin(): void
    {
        $file = $_FILES['plugin_zip'] ?? null;
        if (!is_array($file) || ($file['error'] ?? \UPLOAD_ERR_NO_FILE) !== \UPLOAD_ERR_OK) {
            $this->redirect('/admin/settings/modules', 'No file uploaded or upload failed.', 'error');
            return;
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            $this->redirect('/admin/settings/modules', 'Invalid upload.', 'error');
            return;
        }

        $installer = new \TypeDock\Core\PluginInstaller(TYPEDOCK_ROOT . '/plugins');
        try {
            $result = $installer->install(
                (string) $file['tmp_name'],
                overwrite: !empty($_POST['overwrite']),
            );
            $message = $result['replaced']
                ? "Plugin '{$result['slug']}' was replaced. Enable it from the list below."
                : "Plugin '{$result['slug']}' was installed. Enable it from the list below.";
            $this->redirect('/admin/settings/modules', $message);
        } catch (\Throwable $e) {
            $this->redirect('/admin/settings/modules', 'Install failed: ' . $e->getMessage(), 'error');
        }
    }

    /**
     * Toggle a plugin's enabled flag in site_options. DB state overrides the
     * env fallback in PluginLoader.
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

        $assetNote = '';
        try {
            $publisher = new AssetPublisher(TYPEDOCK_ROOT);
            if ($enabled) {
                $publisher->publishPlugin($slug);
            } else {
                $publisher->unpublishPlugin($slug);
            }
        } catch (\Throwable $e) {
            $assetNote = ' Asset publishing failed; run php cli/assets-publish.php.';
        }

        $this->redirect(
            '/admin/settings/modules',
            sprintf('%s %s.%s', ucfirst($slug), $enabled ? 'enabled' : 'disabled', $assetNote)
        );
    }

    /**
     * Build a unified list of all plugins — legacy built-ins from src/Plugin/ plus
     * drop-ins from plugins/<slug>/ — annotated with their current runtime
     * enabled state and the source of that state (DB / env / default).
     *
     * @return array<int, array{slug:string,name:string,version:string,source:string,enabled:bool,state_source:string,docs_url:?string}>
     */
    private function discoverAllPlugins(): array
    {
        $rows = [];

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
                'enabled'      => $this->resolveEnabled($slug, defaultEnabled: (bool) ($manifest['default_enabled'] ?? false)),
                'state_source' => $this->stateSource($slug),
                'docs_url'     => $this->pluginReadmePath($manifest, dirname($manifestPath)) !== null
                    ? '/admin/plugins/' . rawurlencode($slug) . '/docs'
                    : null,
            ];
        }

        ksort($rows);
        return array_values($rows);
    }

    private function resolveEnabled(string $slug, ?string $envKey = null, bool $defaultEnabled = false): bool
    {
        $db = $this->readDbPluginFlag($slug);
        if ($db !== null) {
            return $db;
        }
        $envKey ??= $this->envKeyForSlug($slug);
        return (bool) env($envKey, $defaultEnabled);
    }

    private function stateSource(string $slug): string
    {
        return $this->readDbPluginFlag($slug) !== null ? 'admin UI' : 'env/default';
    }

    private function envKeyForSlug(string $slug): string
    {
        return 'PLUGIN_' . strtoupper(str_replace('-', '_', $slug));
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

    /**
     * @return array{plugin:array<string,mixed>, markdown:string}|null
     */
    private function pluginReadme(string $slug): ?array
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $slug) !== 1) {
            return null;
        }

        $manifestPath = TYPEDOCK_ROOT . '/plugins/' . $slug . '/plugin.json';
        $manifest = json_decode((string) @file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            return null;
        }

        $path = $this->pluginReadmePath($manifest, dirname($manifestPath));
        if ($path === null) {
            return null;
        }

        $markdown = file_get_contents($path);
        if ($markdown === false) {
            return null;
        }

        return [
            'plugin' => [
                'slug' => $slug,
                'name' => (string) ($manifest['name'] ?? ucfirst($slug)),
                'version' => (string) ($manifest['version'] ?? '—'),
            ],
            'markdown' => $markdown,
        ];
    }

    private function pluginReadmePath(array $manifest, string $pluginDir): ?string
    {
        $readme = trim((string) ($manifest['readme'] ?? 'README.md'));
        if ($readme === '') {
            return null;
        }

        $root = realpath($pluginDir);
        $path = realpath($pluginDir . '/' . ltrim($readme, '/'));
        if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($path) ? $path : null;
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
