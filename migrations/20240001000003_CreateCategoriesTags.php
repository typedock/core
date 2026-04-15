<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateCategoriesTags extends AbstractMigration
{
    public function change(): void
    {
        // categories table
        $categories = $this->table('categories', ['id' => false, 'primary_key' => ['id']]);
        $categories
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('slug', 'string', ['limit' => 255])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('description', 'text', ['null' => true, 'default' => null])
            ->addColumn('parent_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('locale', 'string', ['limit' => 10, 'default' => 'en'])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug', 'locale'], ['unique' => true])
            ->addForeignKey('parent_id', 'categories', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        // tags table
        $tags = $this->table('tags', ['id' => false, 'primary_key' => ['id']]);
        $tags
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('slug', 'string', ['limit' => 255])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('locale', 'string', ['limit' => 10, 'default' => 'en'])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slug', 'locale'], ['unique' => true])
            ->create();

        // page_categories pivot table (composite PK)
        $pageCategories = $this->table('page_categories', ['id' => false, 'primary_key' => ['page_id', 'category_id']]);
        $pageCategories
            ->addColumn('page_id', 'string', ['limit' => 36])
            ->addColumn('category_id', 'string', ['limit' => 36])
            ->addForeignKey('page_id', 'pages', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        // page_tags pivot table (composite PK)
        $pageTags = $this->table('page_tags', ['id' => false, 'primary_key' => ['page_id', 'tag_id']]);
        $pageTags
            ->addColumn('page_id', 'string', ['limit' => 36])
            ->addColumn('tag_id', 'string', ['limit' => 36])
            ->addForeignKey('page_id', 'pages', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('tag_id', 'tags', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
