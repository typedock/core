<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface PluginInterface
{
    public function register(\TypeDock\Core\PluginContext $context): void;

    public function getName(): string;

    public function getVersion(): string;

    /**
     * Single-multiplicity provider types this plugin claims (e.g. ["mailer"]).
     * Core uses this list at load time to detect conflicts when two plugins
     * both claim the same type, and to surface an admin warning. Return an
     * empty list when the plugin is purely additive (components, blocks,
     * admin pages).
     *
     * @return array<int, string>
     */
    public function provides(): array;
}
