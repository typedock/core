<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSnapshots extends AbstractMigration
{
    public function change(): void
    {
        // snapshots table
        $snapshots = $this->table('snapshots', ['id' => false, 'primary_key' => ['id']]);
        $snapshots
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('type', 'string', ['limit' => 100])
            ->addColumn('data', 'text', [])
            ->addColumn('label', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('auto_generated', 'boolean', ['default' => false])
            ->addColumn('user_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['type'])
            ->addIndex(['created_at'])
            ->create();
    }
}
