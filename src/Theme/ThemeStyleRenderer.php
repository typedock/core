<?php
declare(strict_types=1);

namespace TypeDock\Theme;

/**
 * Emits the `--td-*` custom-property block the theme's CSS consumes.
 *
 * The renderer is intentionally stupid: it walks the merged settings tree and
 * projects each scalar field into a CSS custom property named
 * `--td-{group}-{field}` (both slugified). Themes pick and choose which of
 * those they actually want to reference — unused custom properties are free.
 *
 * The core never interprets setting values semantically (no "font X means
 * stack Y" mapping). Themes that need semantic lookup drive it from their
 * own CSS via body classes or `@media` / `var()` fallbacks. Keeping the core
 * free of font/locale vocabulary is what lets the settings system work for
 * any locale an OSS distribution lands in.
 *
 * Fields of type `textarea` or `image` are intentionally skipped — a CSS
 * custom property is a single-line scalar, and those types don't fit.
 * Themes that want to emit multi-line CSS from a textarea do so themselves
 * in the template (inside a dedicated `<style>` block).
 */
class ThemeStyleRenderer
{
    private ThemeSettingsService $settings;

    public function __construct(ThemeSettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Build the CSS source to paste inside an inline `<style>`. Returns an
     * empty string (no `:root{}` wrapper) when the theme has no settings, so
     * callers can wrap in `{if}` or just `|noescape` unconditionally.
     */
    public function renderCssVariables(): string
    {
        $schema = $this->settings->getSchema();
        if ($schema === []) {
            return '';
        }
        $values = $this->settings->all();

        $lines = [];
        foreach ($schema as $groupKey => $group) {
            foreach ($group['fields'] ?? [] as $fieldKey => $field) {
                $type = (string) ($field['type'] ?? 'text');
                if (in_array($type, ['textarea', 'image'], true)) {
                    continue;
                }
                $value    = $values[$groupKey][$fieldKey] ?? null;
                $rendered = $this->renderValue($type, $value);
                if ($rendered === null) {
                    continue;
                }
                $name    = $this->propertyName((string) $groupKey, (string) $fieldKey);
                $lines[] = '    ' . $name . ': ' . $rendered . ';';
            }
        }

        if ($lines === []) {
            return '';
        }
        return ":root {\n" . implode("\n", $lines) . "\n}";
    }

    private function renderValue(string $type, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            // Booleans don't have a clean CSS representation — themes
            // typically branch on them via body classes instead.
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return $this->sanitizeValue((string) $value);
    }

    private function propertyName(string $group, string $field): string
    {
        return '--td-' . $this->slug($group) . '-' . $this->slug($field);
    }

    private function slug(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim($s, '-');
    }

    /**
     * Strip characters that could terminate the CSS declaration. CSS variables
     * are interpolated back into the stylesheet verbatim, so we defence-in-depth
     * the value against `;` / `}` injection even though the admin form is
     * behind auth + CSRF. Linebreaks are flattened too so the declaration
     * stays on one line.
     */
    private function sanitizeValue(string $value): string
    {
        $value = str_replace(["\r", "\n", ';', '}', '{'], ' ', $value);
        return trim($value);
    }
}
