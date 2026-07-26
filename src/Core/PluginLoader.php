<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Contract\PluginInterface;
use TypeDock\Security\AdminCspPolicy;

/**
 * Discovers and boots drop-in plugins under `plugins/<slug>/` from their
 * `plugin.json` manifest. Manifest fields: slug, main_class, version,
 * provides[], min_core_version, autoload.psr-4, and optional admin_csp.
 * Plugins carry their own source code and templates alongside the manifest
 * and do NOT live inside `src/`. Enable rule: DB admin toggle first, then
 * `PLUGIN_<SLUG_UPPER>` env. Boot pipeline: manifest validation → autoload
 * setup → class instantiation → provides() conflict check → register().
 */
class PluginLoader
{
    private const SLUG_REGEX = '/^[a-z][a-z0-9_-]{1,63}$/';

    public function load(): void
    {
        $this->loadDropInPlugins();
    }

    /**
     * Scan plugins/<slug>/plugin.json and boot each plugin whose enable flag
     * is truthy. Enable flag resolution is DB admin toggle first, then
     * `PLUGIN_<SLUG_UPPER>` env. Otherwise plugins are disabled.
     */
    private function loadDropInPlugins(): void
    {
        $pluginsDir = TYPEDOCK_ROOT . '/plugins';
        if (!is_dir($pluginsDir)) {
            return;
        }

        $candidates = [];
        foreach (glob($pluginsDir . '/*/plugin.json') ?: [] as $manifestPath) {
            $slug = basename(dirname($manifestPath));
            if (preg_match(self::SLUG_REGEX, $slug) !== 1) {
                continue;
            }
            $manifest = $this->readManifest($manifestPath);
            if ($manifest === null) {
                \Flight::plugin_diagnostics()->error($slug, 'Invalid manifest JSON.');
                continue;
            }
            $candidates[] = [
                'slug' => $slug,
                'manifest_path' => $manifestPath,
                'manifest' => $manifest,
            ];
        }

        $dbEnabledFlags = $this->readDbEnabledFlags(array_column($candidates, 'slug'));
        foreach ($candidates as $candidate) {
            $slug = $candidate['slug'];
            $manifest = $candidate['manifest'];
            if (!$this->isPluginEnabled(
                $slug,
                defaultEnabled: (bool) ($manifest['default_enabled'] ?? false),
                dbState: $dbEnabledFlags[$slug] ?? null,
            )) {
                continue;
            }
            $this->loadDropInPlugin($slug, $candidate['manifest_path'], $manifest);
        }
    }

    /**
     * Resolve a plugin's enabled/disabled state.
     *
     * Precedence (first hit wins):
     *   1. DB `site_options.plugin.<slug>.enabled` — admin-UI toggle
     *   2. `PLUGIN_<UPPER>` env
     *   3. default false
     */
    private function isPluginEnabled(
        string $slug,
        ?string $envKey = null,
        bool $defaultEnabled = false,
        ?bool $dbState = null,
    ): bool
    {
        if ($dbState !== null) {
            return $dbState;
        }
        $envKey ??= $this->envKeyForSlug($slug);
        return (bool) env($envKey, $defaultEnabled);
    }

    /**
     * Load every discovered plugin's DB override in one query.
     *
     * Missing, malformed, or unavailable rows intentionally fall through to
     * each plugin's environment/default state.
     *
     * @param list<string> $slugs
     * @return array<string,bool>
     */
    private function readDbEnabledFlags(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        try {
            $keys = array_map(
                static fn(string $slug): string => 'plugin.' . $slug . '.enabled',
                $slugs,
            );
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));
            $stmt = \Flight::db()->prepare(
                "SELECT key_name, value FROM site_options WHERE key_name IN ({$placeholders})"
            );
            $stmt->execute($keys);

            $states = [];
            foreach ($stmt->fetchAll() as $row) {
                $key = (string) ($row['key_name'] ?? '');
                if (!str_starts_with($key, 'plugin.') || !str_ends_with($key, '.enabled')) {
                    continue;
                }
                $slug = substr($key, strlen('plugin.'), -strlen('.enabled'));
                if (!in_array($slug, $slugs, true)) {
                    continue;
                }
                $decoded = json_decode((string) ($row['value'] ?? ''), true);
                if (is_bool($decoded)) {
                    $states[$slug] = $decoded;
                }
            }

            return $states;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readManifest(string $manifestPath): ?array
    {
        $raw = @file_get_contents($manifestPath);
        $manifest = $raw === false ? null : json_decode($raw, true);
        return is_array($manifest) ? $manifest : null;
    }

    /**
     * @param array<string, mixed>|null $manifest
     */
    private function loadDropInPlugin(string $slug, string $manifestPath, ?array $manifest = null): void
    {
        $diag = \Flight::plugin_diagnostics();

        $manifest ??= $this->readManifest($manifestPath);
        if (!is_array($manifest)) {
            $diag->error($slug, 'Invalid manifest JSON.');
            return;
        }

        $manifestSlug = (string) ($manifest['slug'] ?? '');
        $class        = (string) ($manifest['main_class'] ?? '');
        if ($manifestSlug !== $slug) {
            $diag->error($slug, "Manifest slug '{$manifestSlug}' does not match directory '{$slug}'.");
            return;
        }
        if ($class === '') {
            $diag->error($slug, 'Manifest is missing main_class.');
            return;
        }

        $requiredCore = (string) ($manifest['min_core_version'] ?? '');
        if ($requiredCore !== '' && !$this->coreVersionSatisfies($requiredCore)) {
            $core = defined('TYPEDOCK_VERSION') ? (string) TYPEDOCK_VERSION : '0.0.0';
            $diag->error($slug, "Requires Core >= {$requiredCore} (running {$core}); skipped.");
            return;
        }

        $adminCsp = $manifest['admin_csp'] ?? [];
        if (!is_array($adminCsp)) {
            $diag->error($slug, 'Manifest admin_csp must be an object.');
            return;
        }
        try {
            AdminCspPolicy::validatePluginSources($adminCsp);
        } catch (\InvalidArgumentException $e) {
            $diag->error($slug, 'Invalid admin_csp: ' . $e->getMessage());
            return;
        }

        $pluginDir = dirname($manifestPath);
        $this->registerManifestAutoload($manifest, $pluginDir);

        // Composer-managed drop-in: may still have its own vendor/.
        $composerAutoload = $pluginDir . '/vendor/autoload.php';
        if (is_file($composerAutoload)) {
            require_once $composerAutoload;
        }

        if (!class_exists($class)) {
            $diag->error($slug, "main_class '{$class}' is not autoloadable — check autoload.psr-4 in manifest.");
            return;
        }

        $plugin = new $class();
        if (!$plugin instanceof PluginInterface) {
            return;
        }

        // Manifest/code provides cross-check.
        $manifestProvides = array_values((array) ($manifest['provides'] ?? []));
        $codeProvides     = array_values($plugin->provides());
        sort($manifestProvides);
        sort($codeProvides);
        if ($manifestProvides !== $codeProvides) {
            $diag->warn($slug, sprintf(
                'manifest/code provides mismatch: manifest=%s code=%s',
                json_encode($manifestProvides),
                json_encode($codeProvides)
            ));
        }

        try {
            $context = $this->bootPlugin($slug, $plugin, $pluginDir);
        } catch (\Throwable $e) {
            $diag->error($slug, 'Plugin registration failed: ' . $e->getMessage());
            return;
        }
        \Flight::admin_csp()->addPluginSources($adminCsp);

        if (!$context->hasAdminSurface() && $this->resolveReadmePath($manifest, $pluginDir) === null) {
            $diag->warn($slug, 'Plugin has no admin UI and no README.md; add plugin.json readme or a root README.md so admins know how to use it.');
        }
    }

    /**
     * Register PSR-4 autoload entries from the plugin manifest. This is how
     * a drop-in plugin publishes its classes — Core isn't in charge of the
     * plugin's source tree layout, so the manifest has to declare it.
     */
    private function registerManifestAutoload(array $manifest, string $pluginDir): void
    {
        $psr4 = $manifest['autoload']['psr-4'] ?? [];
        if (!is_array($psr4)) {
            return;
        }
        foreach ($psr4 as $namespace => $relativeDir) {
            $namespace = rtrim((string) $namespace, '\\') . '\\';
            $base      = rtrim($pluginDir . '/' . ltrim((string) $relativeDir, '/'), '/') . '/';

            spl_autoload_register(function (string $class) use ($namespace, $base): void {
                if (!str_starts_with($class, $namespace)) {
                    return;
                }
                $rel  = substr($class, strlen($namespace));
                $path = $base . str_replace('\\', '/', $rel) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            });
        }
    }

    private function bootPlugin(string $slug, PluginInterface $plugin, ?string $pluginDir = null): PluginContext
    {
        // Pre-register provider claims so the registry can surface a conflict
        // even if the plugin's register() errors out before calling provideSingle().
        $registry = \Flight::provider_registry();
        foreach ($plugin->provides() as $type) {
            $existing = $registry->claimedBy((string) $type);
            if ($existing !== null && $existing !== $slug) {
                $registry->recordConflict((string) $type, $slug);
            }
        }

        $context = new PluginContext($slug, \Flight::db(), $pluginDir);
        $plugin->register($context);
        return $context;
    }

    private function resolveReadmePath(array $manifest, string $pluginDir): ?string
    {
        $readme = trim((string) ($manifest['readme'] ?? 'README.md'));
        if ($readme === '') {
            return null;
        }
        $path = realpath($pluginDir . '/' . ltrim($readme, '/'));
        $root = realpath($pluginDir);
        if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }
        return is_file($path) ? $path : null;
    }

    private function coreVersionSatisfies(string $required): bool
    {
        $core = defined('TYPEDOCK_VERSION') ? (string) TYPEDOCK_VERSION : '0.0.0';
        return version_compare($core, $required, '>=');
    }

    private function envKeyForSlug(string $slug): string
    {
        return 'PLUGIN_' . strtoupper(str_replace('-', '_', $slug));
    }
}
