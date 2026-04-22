<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateCategoriesTags extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('categories', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('slug', 255);
            $t->string('name', 255);
            $t->text('description')->null();
            $t->string('parent_id', 36)->null();
            $t->string('locale', 10)->default('en');
            $t->integer('sort_order')->default(0);
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->unique(['slug', 'locale']);
            $t->foreign('parent_id', 'categories', 'id')->nullOnDelete();
        });

        $schema->create('tags', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('slug', 255);
            $t->string('name', 255);
            $t->string('locale', 10)->default('en');
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->unique(['slug', 'locale']);
        });

        $schema->create('page_categories', function (Blueprint $t) {
            $t->string('page_id', 36);
            $t->string('category_id', 36);
            $t->primary(['page_id', 'category_id']);
            $t->foreign('page_id', 'pages', 'id')->cascadeOnDelete();
            $t->foreign('category_id', 'categories', 'id')->cascadeOnDelete();
        });

        $schema->create('page_tags', function (Blueprint $t) {
            $t->string('page_id', 36);
            $t->string('tag_id', 36);
            $t->primary(['page_id', 'tag_id']);
            $t->foreign('page_id', 'pages', 'id')->cascadeOnDelete();
            $t->foreign('tag_id', 'tags', 'id')->cascadeOnDelete();
        });
    }
}
