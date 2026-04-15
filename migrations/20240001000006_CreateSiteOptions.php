<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSiteOptions extends AbstractMigration
{
    public function change(): void
    {
        // site_options table — key_name is the PK (no auto-increment id)
        $siteOptions = $this->table('site_options', ['id' => false, 'primary_key' => ['key_name']]);
        $siteOptions
            ->addColumn('key_name', 'string', ['limit' => 255])
            ->addColumn('value', 'text', ['null' => true, 'default' => null])
            ->addColumn('group_name', 'string', ['limit' => 100, 'null' => true, 'default' => null])
            ->addColumn('updated_at', 'datetime', [])
            ->addIndex(['group_name'])
            ->create();
    }
}
