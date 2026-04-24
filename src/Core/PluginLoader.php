<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Contract\PluginInterface;

/**
 * Discovers and boots plugins.
 *
 * Two classes of plugin sit side by side:
 *   1. Legacy bundled plugins under `src/Plugin/<Name>/` — declared via the
 *      $builtInPlugins array below. Enabled by `PLUGIN_<NAME>` env flags
 *      (matching the legacy config/modules.php plugins map).
 *   2. Drop-in plugins under `plugins/<slug>/` — auto-discovered from
 *      their plugin.json manifest. Manifest fields: slug, main_class,
 *      version, provides[], min_core_version, autoload.psr-4. These
 *      plugins carry their own source code and templates alongside the
 *      manifest and do NOT live inside `src/` — they are deliberately
 *      decoupled from Core's PSR-4 tree so a plugin author can zip-up
 *      `plugins/<slug>/` and ship it.
 *
 * All plugins go through the same boot pipeline: autoload setup → class
 * instantiation → provides() conflict check → register().
 */
class PluginLoader
{
    private const SLUG_REGEX = '/^[a-z][a-z0-9_-]{1,63}$/';

    /**
     * Bundled plugins that still live inside src/Plugin/. Each entry is
     * [env key => fqcn]. New plugins should prefer the plugins/<slug>/
     * drop-in layout instead.
     */
    private array $builtInPlugins = [
        'AdvancedBlocks' => \TypeDock\Plugin\AdvancedBlocks\AdvancedBlocksPlugin::class,
    ];

    public function load(): void
    {
        $this->loadBuiltInPlugins();
        $this->loadDropInPlugins();
    }

    private function loadBuiltInPlugins(): void
    {
        foreach ($this->builtInPlugins as $name => $class) {
            $slug = strtolower($name);
            if (!$this->isPluginEnabled($slug, $name)) {
                continue;
            }
            if (!class_exists($class)) {
                continue;
            }
            $plugin = new $class();
            if (!$plugin instanceof PluginInterface) {
                continue;
            }
            $this->bootPlugin($slug, $plugin);
        }
    }

    /**
     * Scan plugins/<slug>/plugin.json and boot each plugin whose enable flag
     * is truthy. Enable flag resolution (in order):
     *   1. `config('modules.plugins.<slug>')` — legacy env-based toggle
     *   2. `PLUGIN_<SLUG_UPPER>` env fallback
     *   3. otherwise disabled (explicit opt-in required)
     */
    private function loadDropInPlugins(): void
    {
        $pluginsDir = TYPEDOCK_ROOT . '/plugins';
        if (!is_dir($pluginsDir)) {
            return;
        }

        foreach (glob($pluginsDir . '/*/plugin.json') ?: [] as $manifestPath) {
            $slug = basename(dirname($manifestPath));
            if (preg_match(self::SLUG_REGEX, $slug) !== 1) {
                continue;
            }
            if (!$this->isPluginEnabled($slug, $this->configKeyForSlug($slug))) {
                continue;
            }
            $this->loadDropInPlugin($slug, $manifestPath);
        }
    }

    /**
     * Resolve a plugin's enabled/disabled state.
     *
     * Precedence (first hit wins):
     *   1. DB `site_options.plugin.<slug>.enabled` — admin-UI toggle
     *   2. `config('modules.plugins.<configKey>')` / env `PLUGIN_<UPPER>` — legacy
     *   3. default false
     */
    private function isPluginEnabled(string $slug, string $configKey): bool
    {
        $dbState = $this->readDbEnabledFlag($slug);
        if ($dbState !== null) {
            return $dbState;
        }
        $plugins = (array) config('modules.plugins', []);
        if (array_key_exists($configKey, $plugins)) {
            return (bool) $plugins[$configKey];
        }
        $envKey = 'PLUGIN_' . strtoupper(str_replace('-', '_', $slug));
        return (bool) env($envKey, false);
    }

    private function readDbEnabledFlag(string $slug): ?bool
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

    private function loadDropInPlugin(string $slug, string $manifestPath): void
    {
        $diag = \Flight::plugin_diagnostics();

        $raw      = @file_get_contents($manifestPath);
        $manifest = $raw === false ? null : json_decode($raw, true);
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

        $this->bootPlugin($slug, $plugin, $pluginDir);
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

    private function bootPlugin(string $slug, PluginInterface $plugin, ?string $pluginDir = null): void
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
    }

    private function coreVersionSatisfies(string $required): bool
    {
        $core = defined('TYPEDOCK_VERSION') ? (string) TYPEDOCK_VERSION : '0.0.0';
        return version_compare($core, $required, '>=');
    }

    private function configKeyForSlug(string $slug): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $slug)));
    }
}
