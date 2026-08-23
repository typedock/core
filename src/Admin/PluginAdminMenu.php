<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Auth\PermissionChecker;

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
    /** @var array<int, array{slug:string,label:string,path:string,permission:string}> */
    private array $items = [];

    public function add(string $slug, string $label, string $path, string $permission): void
    {
        $this->items[] = [
            'slug'       => $slug,
            'label'      => $label,
            'path'       => $path,
            'permission' => $permission,
        ];
    }

    /**
     * Only expose entries whose corresponding routes the current user may
     * open. Hiding a menu item is not the authorization boundary — the route
     * enforces the same permission — but advertising a guaranteed 403 is both
     * confusing and an easy way for UI and server policy to drift.
     *
     * @param array<string, mixed>|null $user
     * @return array<int, array{slug:string,label:string,path:string,permission:string}>
     */
    public function visibleTo(?array $user, PermissionChecker $permissions): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            $this->items,
            static fn(array $item): bool => $permissions->can($user, $item['permission']),
        ));
    }
}
