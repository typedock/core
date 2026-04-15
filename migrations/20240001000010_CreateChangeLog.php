<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateChangeLog extends AbstractMigration
{
    public function change(): void
    {
        // change_log table — immutable audit log, no FK constraints (user may be deleted)
        $changeLog = $this->table('change_log', ['id' => false, 'primary_key' => ['id']]);
        $changeLog
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('target_type', 'string', ['limit' => 100])
            ->addColumn('target_id', 'string', ['limit' => 36])
            ->addColumn('action', 'string', ['limit' => 50])
            ->addColumn('changes', 'text', ['null' => true, 'default' => null])
            ->addColumn('user_id', 'string', ['limit' => 36, 'null' => true, 'default' => null])
            ->addColumn('user_name', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['target_type', 'target_id'])
            ->addIndex(['user_id'])
            ->addIndex(['created_at'])
            ->create();
    }
}
