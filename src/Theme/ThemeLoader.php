<?php
declare(strict_types=1);

namespace TypeDock\Theme;

class ThemeLoader
{
    private string $themesDir;

    public function __construct()
    {
        $this->themesDir = TYPEDOCK_ROOT . '/themes';
    }

    /**
     * Load theme.json for active theme.
     *
     * @return array<string, mixed>
     */
    public function loadThemeConfig(string $themeName = 'default'): array
    {
        $path = $this->themesDir . '/' . $themeName . '/theme.json';
        if (!file_exists($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);
        return is_array($json) ? $json : [];
    }

    /**
     * Resolve the currently active theme from site_options. Falls back to 'default'
     * if the option is missing, malformed, or points to a theme that no longer exists.
     */
    public function resolveActiveTheme(\PDO $pdo): string
    {
        try {
            $stmt = $pdo->prepare("SELECT value FROM site_options WHERE key_name = 'theme.active' LIMIT 1");
            $stmt->execute();
            $val = $stmt->fetchColumn();
            if ($val !== false) {
                $decoded = json_decode((string) $val, true);
                if (is_string($decoded) && $decoded !== '' && is_dir($this->themesDir . '/' . $decoded)) {
                    return $decoded;
                }
            }
        } catch (\Throwable) {
            // Fall through to default — happens e.g. before install has run.
        }
        return 'default';
    }

    public function themeExists(string $themeName): bool
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $themeName)) {
            return false;
        }
        return is_dir($this->themesDir . '/' . $themeName)
            && is_file($this->themesDir . '/' . $themeName . '/theme.json');
    }

    /**
     * Switch active theme: clear slot_placements, insert defaults from theme.json.
     */
    public function activateTheme(string $themeName, \PDO $pdo): void
    {
        $config = $this->loadThemeConfig($themeName);
        $slots  = $config['slots'] ?? [];
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // Clear existing placements — the new theme will declare its own
            // set of slots and seed them from theme.json defaults below.
            $pdo->exec('DELETE FROM slot_placements');

            // Theme settings are theme-specific (keys come from the old theme's
            // settings schema). Drop them so the new theme's defaults win.
            $pdo->prepare("DELETE FROM site_options WHERE key_name = 'theme_settings'")->execute();

            // Insert defaults
            foreach ($slots as $slotName => $slotConfig) {
                $defaults = $slotConfig['defaults'] ?? [];
                foreach ($defaults as $order => $item) {
                    $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
                    $pdo->prepare(
                        'INSERT INTO slot_placements (id, slot_name, component_type, params, sort_order, created_at)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $id,
                        $slotName,
                        $item['component'],
                        isset($item['params']) ? json_encode($item['params']) : null,
                        $order,
                        $now,
                    ]);
                }
            }

            // Update active theme in site_options (portable upsert)
            $themeJson = json_encode($themeName);
            $check = $pdo->prepare("SELECT key_name FROM site_options WHERE key_name = 'theme.active'");
            $check->execute();
            if ($check->fetchColumn() !== false) {
                $pdo->prepare("UPDATE site_options SET value = ?, updated_at = ? WHERE key_name = 'theme.active'")
                    ->execute([$themeJson, $now]);
            } else {
                $pdo->prepare(
                    "INSERT INTO site_options (key_name, value, group_name, updated_at)
                     VALUES ('theme.active', ?, 'general', ?)"
                )->execute([$themeJson, $now]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Publish assets after the DB transaction commits so the new theme's
        // CSS/JS are immediately reachable under /themes/{name}/assets/.
        // A publish failure is non-fatal — the activation itself has succeeded
        // and operators can rerun `php cli/assets-publish.php` to recover.
        try {
            (new \TypeDock\Core\AssetPublisher(TYPEDOCK_ROOT))->publishTheme($themeName);
        } catch (\Throwable) {
            // Swallow; the web server may still serve assets via the PHP fallback.
        }
    }

    /**
     * List available themes.
     *
     * @return array<string>
     */
    public function listThemes(): array
    {
        if (!is_dir($this->themesDir)) {
            return [];
        }
        $themes = [];
        foreach (scandir($this->themesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($this->themesDir . '/' . $entry) && is_file($this->themesDir . '/' . $entry . '/theme.json')) {
                $themes[] = $entry;
            }
        }
        sort($themes);
        return $themes;
    }

    /**
     * Resolve the public screenshot URL for a theme, if one exists.
     *
     * Screenshots are looked up inside the theme's assets directory because the
     * AssetPublisher publishes `themes/{name}/assets/` → `public/themes/{name}/assets/`
     * and that's the only location we can guarantee the web server will serve.
     */
    public function screenshotUrl(string $themeName): ?string
    {
        $assetsDir = $this->themesDir . '/' . $themeName . '/assets';
        foreach (['screenshot.svg', 'screenshot.png', 'screenshot.jpg', 'screenshot.webp'] as $candidate) {
            if (is_file($assetsDir . '/' . $candidate)) {
                $base = rtrim((string) config('app.url', ''), '/');
                return $base . '/themes/' . $themeName . '/assets/' . $candidate;
            }
        }
        return null;
    }
}
