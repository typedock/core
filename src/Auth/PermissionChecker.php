<?php
declare(strict_types=1);

namespace TypeDock\Auth;

class PermissionChecker
{
    // Role hierarchy: admin > editor > author > contributor
    private const ROLE_HIERARCHY = ['admin', 'editor', 'author', 'contributor'];

    // Permission matrix: which roles have which permissions
    private const PERMISSIONS = [
        // Site settings
        'settings:manage'   => ['admin'],
        'modules:manage'    => ['admin'],
        'users:manage'      => ['admin'],
        'themes:manage'     => ['admin'],

        // Content
        'posts:publish'     => ['admin', 'editor', 'author'],
        'posts:create'      => ['admin', 'editor', 'author', 'contributor'],
        'posts:edit_any'    => ['admin', 'editor'],
        'posts:delete_any'  => ['admin', 'editor'],
        'posts:edit_own'    => ['admin', 'editor', 'author', 'contributor'],
        'posts:delete_own'  => ['admin', 'editor', 'author'],

        // Pages
        'pages:publish'     => ['admin', 'editor'],
        'pages:create'      => ['admin', 'editor', 'author', 'contributor'],
        'pages:edit_any'    => ['admin', 'editor'],
        'pages:delete_any'  => ['admin', 'editor'],
        'pages:edit_own'    => ['admin', 'editor', 'author', 'contributor'],

        // Media
        'media:upload'      => ['admin', 'editor', 'author', 'contributor'],
        'media:manage_any'  => ['admin', 'editor'],
        'media:manage_own'  => ['admin', 'editor', 'author', 'contributor'],
        'media:delete_own'  => ['admin', 'editor', 'author', 'contributor'],

        // Menus, Categories, Tags
        'menus:manage'      => ['admin', 'editor'],
        'categories:manage' => ['admin', 'editor'],
        'tags:manage'       => ['admin', 'editor', 'author'],

        // Redirects, Slots
        'redirects:manage'  => ['admin', 'editor'],
        'slots:manage'      => ['admin', 'editor'],

        // Role check shortcut
        'role:admin'        => ['admin'],
        'role:editor'       => ['admin', 'editor'],
        'role:author'       => ['admin', 'editor', 'author'],
        'role:contributor'  => ['admin', 'editor', 'author', 'contributor'],
    ];

    /**
     * Check if user has a permission.
     *
     * @param array<string, mixed> $user
     */
    public function can(array $user, string $permission): bool
    {
        $role = (string) ($user['role'] ?? 'contributor');

        if (!isset(self::PERMISSIONS[$permission])) {
            return false;
        }

        return in_array($role, self::PERMISSIONS[$permission], true);
    }

    /**
     * Check if user has a role (or higher in hierarchy).
     *
     * @param array<string, mixed> $user
     */
    public function hasRole(array $user, string $minimumRole): bool
    {
        $userRole    = (string) ($user['role'] ?? 'contributor');
        $userLevel   = array_search($userRole, self::ROLE_HIERARCHY, true);
        $targetLevel = array_search($minimumRole, self::ROLE_HIERARCHY, true);

        if ($userLevel === false || $targetLevel === false) {
            return false;
        }

        return $userLevel <= $targetLevel;
    }

    /**
     * @return array<string>
     */
    public function getAllPermissions(): array
    {
        return array_keys(self::PERMISSIONS);
    }
}
