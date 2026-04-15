<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateMenus extends AbstractMigration
{
    public function change(): void
    {
        // menus table
        $menus = $this->table('menus', ['id' => false, 'primary_key' => ['id']]);
        $menus
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('location', 'string', ['limit' => 100])
            ->addColumn('locale', 'string', ['limit' => 10, 'default' => 'en'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', [])
            ->addIndex(['location', 'locale'], ['unique' => true])
            ->create();

        // menu_items table
        $menuItems = $this->table('menu_items', ['id' => false, 'primary_key' => ['id']]);
        $menuItems
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('menu_id', 'string', ['limit' => 36])
            ->addColumn('parent_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('label', 'string', ['limit' => 255])
            ->addColumn('url', 'string', ['limit' => 2000, 'null' => true, 'default' => null])
            ->addColumn('target_type', 'string', ['limit' => 20, 'null' => true, 'default' => null])
            ->addColumn('target_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('css_class', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('icon', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['menu_id', 'sort_order'])
            ->addForeignKey('menu_id', 'menus', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('parent_id', 'menu_items', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
