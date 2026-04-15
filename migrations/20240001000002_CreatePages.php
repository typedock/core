<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePages extends AbstractMigration
{
    public function change(): void
    {
        // pages table
        $pages = $this->table('pages', ['id' => false, 'primary_key' => ['id']]);
        $pages
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('slug', 'string', ['limit' => 1000])
            ->addColumn('title', 'string', ['limit' => 500])
            ->addColumn('body', 'text', ['null' => true, 'default' => null])
            ->addColumn('excerpt', 'text', ['null' => true, 'default' => null])
            ->addColumn('page_type', 'string', ['limit' => 20, 'default' => 'post'])
            ->addColumn('status', 'string', ['limit' => 20, 'default' => 'draft'])
            ->addColumn('author_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('parent_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('template', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('locale', 'string', ['limit' => 10, 'default' => 'en'])
            ->addColumn('translation_group_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('published_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('scheduled_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', []);

        // slug index: MySQL needs prefix length for long VARCHAR columns
        $adapterType = $this->getAdapter()->getAdapterType();
        if ($adapterType === 'mysql') {
            $pages->addIndex(['slug'], ['limit' => ['slug' => 255]]);
        } else {
            $pages->addIndex(['slug']);
        }

        $pages
            ->addIndex(['translation_group_id'])
            ->addIndex(['page_type', 'status', 'published_at'])
            ->addForeignKey('author_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->addForeignKey('parent_id', 'pages', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        // page_revisions table
        $pageRevisions = $this->table('page_revisions', ['id' => false, 'primary_key' => ['id']]);
        $pageRevisions
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('page_id', 'string', ['limit' => 36])
            ->addColumn('title', 'string', ['limit' => 500])
            ->addColumn('body', 'text', ['null' => true, 'default' => null])
            ->addColumn('author_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('page_id', 'pages', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->addForeignKey('author_id', 'users', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }
}
