<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use TypeDock\Admin\PluginAdminMenu;
use TypeDock\Auth\PermissionChecker;

final class PluginAdminMenuTest extends TestCase
{
    public function testOnlyEntriesAllowedForTheCurrentRoleAreVisible(): void
    {
        $menu = new PluginAdminMenu();
        $menu->add('redirect', 'Redirects', '/admin/plugins/redirect', 'redirects:manage');
        $menu->add('backup', 'Backups', '/admin/plugins/backup', 'role:admin');

        $editorItems = $menu->visibleTo(['role' => 'editor'], new PermissionChecker());
        $this->assertSame(['redirect'], array_column($editorItems, 'slug'));

        $adminItems = $menu->visibleTo(['role' => 'admin'], new PermissionChecker());
        $this->assertSame(['redirect', 'backup'], array_column($adminItems, 'slug'));

        $this->assertSame([], $menu->visibleTo(['role' => 'contributor'], new PermissionChecker()));
        $this->assertSame([], $menu->visibleTo(null, new PermissionChecker()));
    }
}
