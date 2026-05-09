<?php
declare(strict_types=1);

namespace TypeDock\Admin;

/**
 * Collects optional editor extension assets registered by plugins.
 *
 * Core exposes script loading only; the browser-side public API decides what
 * those scripts can read or mutate inside the editor.
 */
final class EditorExtensionRegistry
{
    /** @var array<int, string> */
    private array $scripts = [];

    public function addScript(string $src): void
    {
        $src = trim($src);
        if ($src === '') {
            return;
        }
        if (!in_array($src, $this->scripts, true)) {
            $this->scripts[] = $src;
        }
    }

    /** @return array<int, string> */
    public function scripts(): array
    {
        return $this->scripts;
    }
}
