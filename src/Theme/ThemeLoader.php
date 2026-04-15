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
     * Switch active theme: clear slot_placements, insert defaults from theme.json.
     */
    public function activateTheme(string $themeName, \PDO $pdo): void
    {
        $config = $this->loadThemeConfig($themeName);
        $slots  = $config['slots'] ?? [];
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            // Clear existing placements
            $pdo->exec('DELETE FROM slot_placements');

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
            if (is_dir($this->themesDir . '/' . $entry)) {
                $themes[] = $entry;
            }
        }
        return $themes;
    }
}
