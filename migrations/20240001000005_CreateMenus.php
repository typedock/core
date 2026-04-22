<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateMenus extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('menus', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('name', 255);
            $t->string('location', 100);
            $t->string('locale', 10)->default('en');
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->unique(['location', 'locale']);
        });

        $schema->create('menu_items', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('menu_id', 36);
            $t->string('parent_id', 36)->null();
            $t->string('label', 255);
            $t->string('url', 2000)->null();
            $t->string('target_type', 20)->null();
            $t->string('target_id', 36)->null();
            $t->string('css_class', 255)->null();
            $t->string('icon', 100)->null();
            $t->integer('sort_order')->default(0);
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->index(['menu_id', 'sort_order']);
            $t->foreign('menu_id', 'menus', 'id')->cascadeOnDelete();
            $t->foreign('parent_id', 'menu_items', 'id')->cascadeOnDelete();
        });
    }
}
