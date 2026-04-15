<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSlotPlacements extends AbstractMigration
{
    public function change(): void
    {
        // slot_placements table
        $slotPlacements = $this->table('slot_placements', ['id' => false, 'primary_key' => ['id']]);
        $slotPlacements
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('slot_name', 'string', ['limit' => 100])
            ->addColumn('component_type', 'string', ['limit' => 100])
            ->addColumn('params', 'text', ['null' => true, 'default' => null])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('conditions', 'text', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['slot_name', 'sort_order'])
            ->create();
    }
}
