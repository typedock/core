<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Component\ComponentDefinition;
use TypeDock\Contract\CaptchaProvider;
use TypeDock\Contract\MailerInterface;
use TypeDock\Contract\MediaProcessor;
use TypeDock\Contract\RedirectResolver;
use TypeDock\Contract\StorageDriver;
use TypeDock\ExternalSource\ExternalSourceAdapterInterface;
use TypeDock\Import\ImporterInterface;
use TypeDock\Middleware\AuthMiddleware;
use TypeDock\Middleware\CsrfMiddleware;
use TypeDock\Plugin\Util\FileCache;
use TypeDock\Plugin\Util\HttpClient;
use TypeDock\Plugin\Util\PluginLogger;
use TypeDock\Plugin\Util\PluginMigrationRunner;

/**
 * Facade passed to a plugin's register() method. This is NOT a hard security
 * sandbox — plugin PHP runs in the same process as Core (see doc28) — but it
 * is the stable, documented surface that plugins should use.
 *
 * Plugins that stay inside this API:
 *   - keep working across Core refactors (we commit to its major-version
 *     stability)
 *   - get DB / HTTP / cache / logging / mail / storage / templates / migrations
 *     for free
 *   - have their public endpoints namespaced under /plugins/<slug>/
 *   - have their admin pages auto-wrapped in an iframe so plugin CSS can't
 *     collide with Core's admin shell
 */
class PluginContext
{
    private ?PluginDatabase $db = null;
    private ?HttpClient $http = null;
    private ?FileCache $cache = null;
    private ?PluginLogger $logger = null;
    private ?PluginMigrationRunner $migrations = null;
    private bool $hasAdminSurface = false;

    public function __construct(
        private readonly string $pluginSlug,
        private readonly \PDO $pdo,
        private readonly ?string $pluginDir = null
    ) {}

    public function slug(): string
    {
        return $this->pluginSlug;
    }

    /**
     * Absolute path to the plugin's root directory. Set for drop-in plugins
     * under `plugins/<slug>/`; null for bundled `src/Plugin/` plugins (which
     * don't have a single "plugin directory" — their templates/migrations
     * live at known Core paths).
     */
    public function pluginDir(): ?string
    {
        return $this->pluginDir;
    }

    // -----------------------------------------------------------------
    // Registration surface
    // -----------------------------------------------------------------

    public function registerComponent(ComponentDefinition $def): void
    {
        \Flight::components()->register($def);
    }

    public function registerBlock(ComponentDefinition $def): void
    {
        if (!in_array('block', $def->placeable, true)) {
            throw new \InvalidArgumentException(
                "Block component '{$def->type}' must include 'block' in placeable."
            );
        }
        \Flight::components()->register($def);
    }

    public function registerRoute(string $method, string $path, callable $handler): void
    {
        $path = ltrim($path, '/');
        $base = '/plugins/' . $this->pluginSlug;
        $full = $path === '' ? $base : $base . '/' . $path;
        \Flight::route(strtoupper($method) . ' ' . $full, $handler);
    }

    /**
     * Register an admin-area route under /admin/plugins/<slug>/. Wraps the
     * handler with:
     *   - permission enforcement (admin-only by default)
     *   - CSRF verification on mutating verbs (unless requireCsrf=false)
     *   - iframe shell rendering on top-level GETs (doc28 §2.1): the first
     *     time a safe GET hits the plugin, Core returns the admin chrome +
     *     an <iframe> pointing back at the same URL with ?_iframed=1. The
     *     plugin's own handler only executes inside the iframe.
     */
    public function registerAdminRoute(
        string $method,
        string $path,
        callable $handler,
        bool $requireCsrf = true,
        string $permission = 'role:admin',
    ): void {
        $this->hasAdminSurface = true;
        $path   = ltrim($path, '/');
        $base   = '/admin/plugins/' . $this->pluginSlug;
        $full   = $path === '' ? $base : $base . '/' . $path;
        $verb   = strtoupper($method);
        $isSafe = in_array($verb, ['GET', 'HEAD'], true);
        $slug   = $this->pluginSlug;

        \Flight::route($verb . ' ' . $full, function (...$args) use ($handler, $isSafe, $requireCsrf, $slug, $permission) {
            (new AuthMiddleware())->requirePermission($permission);
            if (!$isSafe && $requireCsrf) {
                (new CsrfMiddleware())->verifyOrFail();
            }

            // Top-level GET: render admin shell with iframe embedding the same
            // URL + _iframed=1. Mutating verbs and iframe child requests bypass.
            if ($isSafe && empty($_GET['_iframed'])) {
                (new PluginIframeShell($slug))->dispatch();
                return;
            }

            return $handler(...$args);
        });
    }

    public function addAdminMenuItem(
        string $label,
        string $path,
        string $permission = 'role:admin',
    ): void
    {
        $this->hasAdminSurface = true;
        $path = ltrim($path, '/');
        $base = '/admin/plugins/' . $this->pluginSlug;
        $full = $path === '' ? $base : $base . '/' . $path;
        \Flight::plugin_admin_menu()->add($this->pluginSlug, $label, $full, $permission);
    }

    /**
     * Register a browser script loaded on the post/page editor screen.
     *
     * Relative paths resolve to /plugins/<slug>/assets/<path> after the
     * standard asset publisher has copied plugin assets into public/.
     */
    public function registerEditorScript(string $pathOrUrl): void
    {
        $src = trim($pathOrUrl);
        if ($src === '') {
            return;
        }
        if (!str_starts_with($src, '/') && !preg_match('#^https?://#i', $src)) {
            $asset = ltrim($src, '/');
            $src = '/plugins/' . $this->pluginSlug . '/assets/' . $asset;
            $sourceFile = $this->pluginDir !== null
                ? rtrim($this->pluginDir, '/') . '/assets/' . $asset
                : null;
            if ($sourceFile !== null && is_file($sourceFile)) {
                $src .= '?v=' . filemtime($sourceFile);
            }
        }
        \Flight::editor_extensions()->addScript($src);
    }

    public function hasAdminSurface(): bool
    {
        return $this->hasAdminSurface;
    }

    public function provideSingle(string $type, object $instance): void
    {
        \Flight::provider_registry()->provide($type, $instance, $this->pluginSlug);
    }

    public function registerExternalSourceAdapter(ExternalSourceAdapterInterface $adapter): void
    {
        \Flight::external_source_adapters()->register($adapter);
    }

    /**
     * Teach TypeDock to read another CMS's export format. Core owns the
     * database, resumption and deduplication; the importer only reads a file
     * and yields documents.
     */
    public function registerImporter(ImporterInterface $importer): void
    {
        \Flight::importers()->register($importer);
    }

    public function onMediaUpload(callable|MediaProcessor $handler): void
    {
        $processor = $handler instanceof MediaProcessor
            ? $handler
            : new class($handler) implements MediaProcessor {
                /** @var callable */
                private $fn;
                public function __construct(callable $fn)
                {
                    $this->fn = $fn;
                }
                public function process(string $filePath, string $mimeType): string
                {
                    $out = ($this->fn)($filePath, $mimeType);
                    return is_string($out) && $out !== '' ? $out : $filePath;
                }
            };

        \Flight::media_service()->addProcessor($processor);
    }

    public function addRedirectResolver(RedirectResolver $resolver): void
    {
        \TypeDock\Middleware\RedirectMiddleware::addResolver($resolver);
    }

    public function configureImageProcessing(int $jpegQuality, int $webpQuality, int $maxEdge): void
    {
        $media = \Flight::media_service();
        $media->setImageQualities($jpegQuality, $webpQuality);
        $media->setMaxImageSize($maxEdge, $maxEdge);
    }

    // -----------------------------------------------------------------
    // Iframe admin helpers — plugins call these from their templates /
    // controllers to keep working inside the iframe wrap
    // -----------------------------------------------------------------

    /** True when the current request is the iframe-embedded child view. */
    public function iframed(): bool
    {
        return (string) ($_GET['_iframed'] ?? '') === '1';
    }

    /**
     * Build an admin URL under /admin/plugins/<slug>/<path>. Preserves the
     * iframe flag so links / form actions keep the user inside the iframe
     * after they submit or navigate.
     *
     * @param array<string, scalar> $query
     */
    public function adminUrl(string $path = '', array $query = []): string
    {
        $path = ltrim($path, '/');
        $base = '/admin/plugins/' . $this->pluginSlug;
        $url  = $path === '' ? $base : $base . '/' . $path;

        if ($this->iframed()) {
            $query['_iframed'] = '1';
        }
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $url;
    }

    /**
     * Redirect helper that honours iframe mode (keeps _iframed=1 on the
     * target URL) and writes an optional flash message. Exits after sending
     * the Location header — plugin controllers don't need their own exit.
     */
    public function redirect(string $path, string $flashMessage = '', string $flashType = 'success'): void
    {
        if ($flashMessage !== '') {
            $this->flash($flashType, $flashMessage);
        }

        // If caller passed an already-built URL (with query), respect it as-is
        // but still append _iframed=1 if we're inside the iframe.
        $url = str_starts_with($path, '/')
            ? $this->preserveIframeOn($path)
            : $this->adminUrl($path);

        \Flight::redirect($url);
        exit;
    }

    /** Session flash setter. Keys are prefixed per-plugin to avoid collisions. */
    public function flash(string $type, string $message): void
    {
        typedock_session_start();
        $_SESSION['plugin_flash.' . $this->pluginSlug . '.' . $type] = $message;
    }

    public function getFlash(string $type): ?string
    {
        typedock_session_start();
        $key = 'plugin_flash.' . $this->pluginSlug . '.' . $type;
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return is_string($msg) ? $msg : null;
    }

    // -----------------------------------------------------------------
    // Utilities: the 90%-case toolbox
    // -----------------------------------------------------------------

    public function db(): PluginDatabase
    {
        return $this->db ??= new PluginDatabase($this->pdo, $this->pluginSlug);
    }

    public function http(): HttpClient
    {
        return $this->http ??= new HttpClient($this->pluginSlug);
    }

    public function cache(): FileCache
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $base = (string) config('cache.plugin_dir', TYPEDOCK_ROOT . '/storage/cache/plugins');
        return $this->cache = new FileCache($base . '/' . $this->pluginSlug);
    }

    public function log(): PluginLogger
    {
        if ($this->logger !== null) {
            return $this->logger;
        }
        $base = (string) config('cache.log_dir', TYPEDOCK_ROOT . '/storage/logs');
        return $this->logger = new PluginLogger($base . '/plugins/' . $this->pluginSlug . '.log');
    }

    /**
     * Idempotent SQL migration runner.
     *
     * @param string|array<int, string> $target Directory path or explicit list
     */
    public function migrate(string|array $target): void
    {
        $runner = $this->migrations ??= new PluginMigrationRunner($this->pdo, $this->pluginSlug);
        if (is_string($target)) {
            $runner->runFromDirectory($target);
        } else {
            $runner->runFiles($target);
        }
    }

    public function mail(): MailerInterface
    {
        $override = \Flight::provider_registry()->get('mailer');
        if ($override instanceof MailerInterface) {
            return $override;
        }
        return \Flight::mailer();
    }

    public function captcha(): CaptchaProvider
    {
        return \Flight::captcha();
    }

    public function storage(): StorageDriver
    {
        return \Flight::storage();
    }

    /**
     * Render a Latte template and return the HTML. The template path is
     * resolved against the plugin directory when it's a relative path and
     * pluginDir() is known — otherwise it's passed through to the Latte
     * factory (which applies its own theme/admin resolution).
     *
     * @param array<string, mixed> $params
     */
    public function render(string $template, array $params = []): string
    {
        $params = $this->mergeTemplateDefaults($params);
        $abs    = $this->resolveTemplate($template);
        return \Flight::latte()->renderToString($abs, $params);
    }

    /**
     * Render + echo. Convenient for plugin admin controllers that would
     * otherwise have to say `echo $ctx->render(...)`.
     *
     * @param array<string, mixed> $params
     */
    public function view(string $template, array $params = []): void
    {
        echo $this->render($template, $params);
    }

    // -----------------------------------------------------------------
    // Site options
    // -----------------------------------------------------------------

    public function getSiteOption(string $key): mixed
    {
        $stmt = $this->pdo->prepare('SELECT value FROM site_options WHERE key_name = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row === false ? null : json_decode((string) $row['value'], true);
    }

    public function setSiteOption(string $key, mixed $value, ?string $group = null): void
    {
        $group  ??= 'plugin:' . $this->pluginSlug;
        $encoded  = json_encode($value, JSON_UNESCAPED_UNICODE);
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $exists = $this->pdo->prepare('SELECT 1 FROM site_options WHERE key_name = ?');
        $exists->execute([$key]);
        if ($exists->fetchColumn() !== false) {
            $this->pdo->prepare(
                'UPDATE site_options SET value = ?, group_name = ?, updated_at = ? WHERE key_name = ?'
            )->execute([$encoded, $group, $now, $key]);
        } else {
            $this->pdo->prepare(
                'INSERT INTO site_options (key_name, value, group_name, updated_at) VALUES (?, ?, ?, ?)'
            )->execute([$key, $encoded, $group, $now]);
        }
    }

    public function getCurrentUser(): ?array
    {
        return \Flight::get('current_user');
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    private function resolveTemplate(string $template): string
    {
        // Absolute paths pass through untouched.
        if (str_starts_with($template, '/')) {
            return $template;
        }
        // Drop-in plugins ship their own templates; look under pluginDir first.
        if ($this->pluginDir !== null) {
            $candidate = rtrim($this->pluginDir, '/') . '/' . ltrim($template, '/');
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        // Last resort: let the Latte factory apply its normal resolution.
        return $template;
    }

    /**
     * Defaults injected into every template rendered through this context —
     * lets plugin templates reference `{$ctx}`, `{$admin_url('new')}`,
     * `{$csrf_token}`, and the current user without having to set them
     * explicitly from each controller method.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function mergeTemplateDefaults(array $params): array
    {
        $admin_url = fn(string $p = '', array $q = []) => $this->adminUrl($p, $q);

        return array_merge([
            'ctx'              => $this,
            'admin_url'        => $admin_url,
            'csrf_token'       => CsrfMiddleware::generate(),
            'current_user'     => $this->getCurrentUser(),
            'iframed'          => $this->iframed(),
            'current_path'     => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            // Absolute path to the standard plugin-ui layout. Plugin templates
            // opt into Core-provided chrome with `{layout $plugin_ui_layout}`.
            'plugin_ui_layout' => TYPEDOCK_ROOT . '/admin/layouts/plugin-ui.latte',
        ], $params);
    }

    /** Append _iframed=1 to an absolute path when we're inside the iframe. */
    private function preserveIframeOn(string $url): string
    {
        if (!$this->iframed() || str_contains($url, '_iframed=')) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . '_iframed=1';
    }
}
