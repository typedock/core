<?php
declare(strict_types=1);

namespace TypeDock\Theme;

/**
 * Loads the active theme's `settings` schema from theme.json, merges it with
 * values persisted to site_options, and exposes a read/write API that
 * templates and the admin form both use.
 *
 * Settings are stored under a single site_options row (key: `theme_settings`),
 * as a JSON document keyed by group → field → value. That keeps the writes
 * atomic and lets `reset()` drop the whole row in one statement when a theme
 * is activated.
 */
class ThemeSettingsService
{
    private const OPTION_KEY = 'theme_settings';

    private \PDO $pdo;
    private ThemeLoader $loader;
    private ?string $activeTheme = null;

    /** @var array<string, mixed>|null */
    private ?array $schemaCache = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $valuesCache = null;

    public function __construct(\PDO $pdo, ?ThemeLoader $loader = null)
    {
        $this->pdo    = $pdo;
        $this->loader = $loader ?? new ThemeLoader();
    }

    /**
     * Force the service to treat a given theme as active — used by the admin
     * preview path so `?preview_theme=x` sees its own settings schema rather
     * than the persisted one.
     */
    public function setActiveTheme(string $theme): void
    {
        $this->activeTheme = $theme;
        $this->schemaCache = null;
        $this->valuesCache = null;
    }

    /**
     * Return the merged settings tree (schema defaults ← stored values),
     * shaped as [group => [field => value]].
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->valuesCache !== null) {
            return $this->valuesCache;
        }

        $schema = $this->getSchema();
        $stored = $this->loadStoredValues();

        $out = [];
        foreach ($schema as $groupKey => $group) {
            $out[$groupKey] = [];
            foreach (($group['fields'] ?? []) as $fieldKey => $field) {
                $default = $field['default'] ?? null;
                $value   = $stored[$groupKey][$fieldKey] ?? $default;
                $out[$groupKey][$fieldKey] = $this->coerce($field, $value);
            }
        }

        $this->valuesCache = $out;
        return $out;
    }

    /**
     * Dot-path accessor: `$service->get('colors.primary')`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $parts = explode('.', $key, 2);
        if (count($parts) !== 2) {
            return $default;
        }
        [$group, $field] = $parts;
        $all = $this->all();
        return $all[$group][$field] ?? $default;
    }

    /**
     * Alias used by templates: `{$theme->setting('colors.primary')}`.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->get($key, $default);
    }

    /**
     * Persist a posted `group.field` => value map. Values are coerced against
     * the schema; unknown keys are dropped silently.
     *
     * @param array<string, mixed> $input either flat (`colors.primary` => '#fff')
     *                                    or nested (`colors` => [...]).
     */
    public function save(array $input): void
    {
        $schema    = $this->getSchema();
        $flattened = $this->flatten($input);

        $out = [];
        foreach ($schema as $groupKey => $group) {
            foreach (($group['fields'] ?? []) as $fieldKey => $field) {
                $dotted = $groupKey . '.' . $fieldKey;
                $raw    = $flattened[$dotted] ?? null;

                // Checkboxes don't POST when unchecked, so a missing value for
                // a boolean field means "false", not "fall back to default".
                $type = (string) ($field['type'] ?? 'text');
                if ($type === 'boolean') {
                    $out[$groupKey][$fieldKey] = (bool) $raw;
                    continue;
                }

                if ($raw === null || $raw === '') {
                    // Let the default win on empty submission so the UI doesn't
                    // have to round-trip the default value explicitly.
                    if (array_key_exists('default', $field)) {
                        $out[$groupKey][$fieldKey] = $this->coerce($field, $field['default']);
                    }
                    continue;
                }

                $out[$groupKey][$fieldKey] = $this->coerce($field, $raw);
            }
        }

        $this->writeStored($out);
        $this->valuesCache = null;
    }

    /**
     * Clear the persisted theme_settings row. Called from ThemeLoader when a
     * theme is activated, so the new theme starts from its own defaults.
     */
    public function reset(): void
    {
        $this->pdo
            ->prepare('DELETE FROM site_options WHERE key_name = ?')
            ->execute([self::OPTION_KEY]);
        $this->valuesCache = null;
        $this->schemaCache = null;
        $this->activeTheme = null;
    }

    /**
     * Return the raw settings schema (`settings` block from theme.json),
     * normalised to always have `label` and `fields` keys per group.
     *
     * @return array<string, array{label: string, fields: array<string, array<string, mixed>>}>
     */
    public function getSchema(): array
    {
        if ($this->schemaCache !== null) {
            return $this->schemaCache;
        }

        $theme  = $this->activeTheme();
        $config = $this->loader->loadThemeConfig($theme);
        $raw    = $config['settings'] ?? [];

        $out = [];
        foreach ($raw as $groupKey => $group) {
            if (!is_array($group)) {
                continue;
            }
            $fields = [];
            foreach (($group['fields'] ?? []) as $fieldKey => $field) {
                if (!is_array($field) || !isset($field['type'])) {
                    continue;
                }
                $fields[(string) $fieldKey] = $field;
            }
            $out[(string) $groupKey] = [
                'label'  => (string) ($group['label'] ?? $groupKey),
                'fields' => $fields,
            ];
        }

        $this->schemaCache = $out;
        return $out;
    }

    /**
     * True if the active theme declares any settings. Used by the admin UI to
     * decide whether to surface the "Theme settings" nav item.
     */
    public function hasSchema(): bool
    {
        return $this->getSchema() !== [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadStoredValues(): array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT value FROM site_options WHERE key_name = ? LIMIT 1'
            );
            $stmt->execute([self::OPTION_KEY]);
            $raw = $stmt->fetchColumn();
            if ($raw === false) {
                return [];
            }
            $decoded = json_decode((string) $raw, true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, array<string, mixed>> $values
     */
    private function writeStored(array $values): void
    {
        $json = (string) json_encode($values);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $check = $this->pdo->prepare('SELECT key_name FROM site_options WHERE key_name = ? LIMIT 1');
        $check->execute([self::OPTION_KEY]);
        if ($check->fetchColumn() !== false) {
            $this->pdo
                ->prepare('UPDATE site_options SET value = ?, updated_at = ? WHERE key_name = ?')
                ->execute([$json, $now, self::OPTION_KEY]);
        } else {
            $this->pdo
                ->prepare(
                    "INSERT INTO site_options (key_name, value, group_name, updated_at)
                     VALUES (?, ?, 'theme', ?)"
                )
                ->execute([self::OPTION_KEY, $json, $now]);
        }
    }

    /**
     * Coerce a raw value to the field's declared type so round-trips through
     * JSON stay predictable (numbers as ints, booleans as bools, etc.).
     *
     * @param array<string, mixed> $field
     */
    private function coerce(array $field, mixed $value): mixed
    {
        $type = (string) ($field['type'] ?? 'text');
        return match ($type) {
            'number'  => $value === null || $value === '' ? null : (int) $value,
            'boolean' => (bool) $value,
            'select'  => $this->coerceSelect($field, $value),
            default   => $value === null ? '' : (string) $value,
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    private function coerceSelect(array $field, mixed $value): string
    {
        $options = (array) ($field['options'] ?? []);
        $keys    = array_map('strval', array_keys($options));
        $str     = (string) ($value ?? '');
        if ($str === '' || !in_array($str, $keys, true)) {
            return (string) ($field['default'] ?? ($keys[0] ?? ''));
        }
        return $str;
    }

    /**
     * Accept both flat ("colors.primary" => ...) and nested ("colors" => [...])
     * form submissions. The admin template happens to emit flat keys, but the
     * same service is useful from CLI/tests which prefer nesting.
     *
     * @param  array<string, mixed> $input
     * @return array<string, mixed> dotted => scalar
     */
    private function flatten(array $input): array
    {
        $out = [];
        foreach ($input as $key => $val) {
            $key = (string) $key;
            if (is_array($val)) {
                foreach ($val as $subKey => $subVal) {
                    $out[$key . '.' . $subKey] = $subVal;
                }
            } else {
                $out[$key] = $val;
            }
        }
        return $out;
    }

    private function activeTheme(): string
    {
        if ($this->activeTheme !== null) {
            return $this->activeTheme;
        }
        try {
            $this->activeTheme = $this->loader->resolveActiveTheme($this->pdo);
        } catch (\Throwable) {
            $this->activeTheme = 'default';
        }
        return $this->activeTheme;
    }
}
