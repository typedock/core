<?php
declare(strict_types=1);

namespace TypeDock\Theme;

/**
 * The `$theme` global available inside theme templates.
 *
 * Holds the identity of the active theme (`url`, `name`) and forwards
 * `setting('group.field')` lookups to the ThemeSettingsService. Kept in a
 * real class — rather than a stdClass anon — so Latte's strict-mode
 * property/method resolution doesn't trip on it.
 */
class ThemeContext
{
    public function __construct(
        public readonly string $url,
        public readonly string $name,
        private ThemeSettingsService $settings,
    ) {}

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->get($key, $default);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function settings(): array
    {
        return $this->settings->all();
    }
}
