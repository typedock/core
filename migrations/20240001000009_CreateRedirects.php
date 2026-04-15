<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRedirects extends AbstractMigration
{
    public function change(): void
    {
        // redirects table — Core version: exact match only
        $redirects = $this->table('redirects', ['id' => false, 'primary_key' => ['id']]);
        $redirects
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('source_path', 'string', ['limit' => 2000])
            ->addColumn('target_url', 'string', ['limit' => 2000])
            ->addColumn('status_code', 'integer', ['default' => 301])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);

        // UNIQUE index on source_path: MySQL requires a prefix length for VARCHAR > 191
        $adapterType = $this->getAdapter()->getAdapterType();
        if ($adapterType === 'mysql') {
            $redirects->addIndex(['source_path'], ['unique' => true, 'limit' => ['source_path' => 255]]);
        } else {
            $redirects->addIndex(['source_path'], ['unique' => true]);
        }

        $redirects->create();
    }
}
