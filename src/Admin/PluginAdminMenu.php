<?php
declare(strict_types=1);

namespace TypeDock\Admin;

/**
 * Registry of sidebar entries contributed by plugins. Plugins push items in
 * during their register() call; the admin layout reads the full list via
 * BaseAdminController to render a "Plugins" section.
 *
 * This intentionally stays simple — one label + one target URL per plugin —
 * so the admin chrome doesn't have to know anything about which plugin is
 * authoring which page.
 */
class PluginAdminMenu
{
    /** @var array<int, array{slug:string,label:string,path:string}> */
    private array $items = [];

    public function add(string $slug, string $label, string $path): void
    {
        $this->items[] = ['slug' => $slug, 'label' => $label, 'path' => $path];
    }

    /** @return array<int, array{slug:string,label:string,path:string}> */
    public function all(): array
    {
        return $this->items;
    }
}
