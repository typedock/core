<?php
declare(strict_types=1);

namespace TypeDock\Core;

/**
 * Copies static assets from themes/plugins source directories into public/
 * so that the web server can serve them directly without PHP overhead.
 */
class AssetPublisher
{
    private string $root;
    private string $publicDir;

    public function __construct(?string $root = null, ?string $publicDir = null)
    {
        $this->root      = $root ?? TYPEDOCK_ROOT;
        $this->publicDir = $publicDir
            ?? (defined('TYPEDOCK_PUBLIC_DIR') ? TYPEDOCK_PUBLIC_DIR : $this->root . '/public');
    }

    /**
     * Publish assets for all active themes and plugins.
     *
     * @return array<string, string> Map of source => destination for published directories
     */
    public function publishAll(): array
    {
        $published = [];

        foreach ($this->discoverThemes() as $theme) {
            $result = $this->publishTheme($theme);
            if ($result !== null) {
                $published[$result[0]] = $result[1];
            }
        }

        foreach ($this->discoverPlugins() as $plugin) {
            $result = $this->publishPlugin($plugin);
            if ($result !== null) {
                $published[$result[0]] = $result[1];
            }
        }

        return $published;
    }

    /**
     * Publish a single theme's assets.
     *
     * @return array{string, string}|null [source, destination] or null if no assets
     */
    public function publishTheme(string $themeName): ?array
    {
        $source = $this->root . '/themes/' . $themeName . '/assets';
        $dest   = $this->publicDir . '/themes/' . $themeName . '/assets';

        if (!is_dir($source)) {
            return null;
        }

        $this->mirror($source, $dest);
        return [$source, $dest];
    }

    /**
     * Publish a single plugin's assets.
     *
     * @return array{string, string}|null [source, destination] or null if no assets
     */
    public function publishPlugin(string $pluginSlug): ?array
    {
        $source = $this->root . '/plugins/' . $pluginSlug . '/assets';
        $dest   = $this->publicDir . '/plugins/' . $pluginSlug . '/assets';

        if (!is_dir($source)) {
            return null;
        }

        $this->mirror($source, $dest);
        return [$source, $dest];
    }

    /**
     * Remove published assets for a theme.
     */
    public function unpublishTheme(string $themeName): void
    {
        $dest = $this->publicDir . '/themes/' . $themeName;
        $this->removeDir($dest);
    }

    /**
     * Remove published assets for a plugin.
     */
    public function unpublishPlugin(string $pluginSlug): void
    {
        $dest = $this->publicDir . '/plugins/' . $pluginSlug;
        $this->removeDir($dest);
    }

    /**
     * Discover theme directories that have assets.
     *
     * @return list<string> Theme names
     */
    private function discoverThemes(): array
    {
        $themesDir = $this->root . '/themes';
        if (!is_dir($themesDir)) {
            return [];
        }

        $themes = [];
        foreach (scandir($themesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($themesDir . '/' . $entry . '/assets')) {
                $themes[] = $entry;
            }
        }
        return $themes;
    }

    /**
     * Discover plugin directories that have assets.
     *
     * @return list<string> Plugin slugs
     */
    private function discoverPlugins(): array
    {
        $pluginsDir = $this->root . '/plugins';
        if (!is_dir($pluginsDir)) {
            return [];
        }

        $plugins = [];
        foreach (scandir($pluginsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($pluginsDir . '/' . $entry . '/assets')) {
                $plugins[] = $entry;
            }
        }
        return $plugins;
    }

    /**
     * Mirror source directory to destination (clean copy).
     */
    private function mirror(string $source, string $dest): void
    {
        // Remove existing published assets for a clean copy.
        if (is_dir($dest)) {
            $this->removeDir($dest);
        }

        $this->copyDir($source, $dest);
    }

    /**
     * Recursively copy a directory.
     */
    private function copyDir(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $srcPath  = $source . '/' . $entry;
            $destPath = $dest . '/' . $entry;

            if (is_dir($srcPath)) {
                $this->copyDir($srcPath, $destPath);
            } else {
                copy($srcPath, $destPath);
            }
        }
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
