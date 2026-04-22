<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateSlotPlacements extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('slot_placements', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('slot_name', 100);
            $t->string('component_type', 100);
            $t->text('params')->null();
            $t->integer('sort_order')->default(0);
            $t->text('conditions')->null();
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->index(['slot_name', 'sort_order']);
        });
    }
}
