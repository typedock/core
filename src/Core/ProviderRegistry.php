<?php
declare(strict_types=1);

namespace TypeDock\Core;

/**
 * Registry for single-multiplicity provider types ("mailer", "storage",
 * "search", "captcha"). Last-wins with an admin warning when a second
 * plugin tries to claim a type already owned by a loaded plugin.
 *
 * Core registers its default implementations first (during ServiceProvider
 * boot); plugins override via PluginContext::provideSingle(). The collected
 * warnings are surfaced to admin users via the dashboard / plugin page.
 */
class ProviderRegistry
{
    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, string> */
    private array $claimedBy = [];

    /** @var array<int, string> */
    private array $warnings = [];

    public function provide(string $type, object $instance, string $pluginSlug): void
    {
        if (isset($this->instances[$type]) && ($this->claimedBy[$type] ?? '') !== $pluginSlug) {
            $this->warnings[] = sprintf(
                "Plugin '%s' is replacing the '%s' provider previously registered by '%s'.",
                $pluginSlug,
                $type,
                $this->claimedBy[$type] ?? 'core'
            );
        }
        $this->instances[$type] = $instance;
        $this->claimedBy[$type] = $pluginSlug;
    }

    public function get(string $type): ?object
    {
        return $this->instances[$type] ?? null;
    }

    public function claimedBy(string $type): ?string
    {
        return $this->claimedBy[$type] ?? null;
    }

    /**
     * Record a conflict detected at manifest-load time, before any instance
     * is provided. Lets Core surface "two plugins both claim mailer" even
     * when one of them errors out in its register() method.
     */
    public function recordConflict(string $type, string $newPluginSlug): void
    {
        $this->warnings[] = sprintf(
            "Provider conflict: '%s' is claimed by both '%s' and '%s'.",
            $type,
            $this->claimedBy[$type] ?? 'unknown',
            $newPluginSlug
        );
    }

    /** @return array<int, string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->claimedBy;
    }
}
