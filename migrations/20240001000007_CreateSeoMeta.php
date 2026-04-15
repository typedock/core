<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSeoMeta extends AbstractMigration
{
    public function change(): void
    {
        // seo_meta table
        $seoMeta = $this->table('seo_meta', ['id' => false, 'primary_key' => ['id']]);
        $seoMeta
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('target_type', 'string', ['limit' => 50])
            ->addColumn('target_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('seo_title', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('meta_description', 'text', ['null' => true, 'default' => null])
            ->addColumn('canonical_url', 'string', ['limit' => 2000, 'null' => true, 'default' => null])
            ->addColumn('robots', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('og_title', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('og_description', 'text', ['null' => true, 'default' => null])
            ->addColumn('og_image_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('twitter_card', 'string', ['limit' => 50, 'null' => true, 'default' => null])
            ->addColumn('focus_keyword', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('schema_type', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', [])
            ->addIndex(['target_type', 'target_id'], ['unique' => true])
            ->addForeignKey('og_image_id', 'media', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();
    }
}
