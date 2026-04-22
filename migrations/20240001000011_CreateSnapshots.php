<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateSnapshots extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('snapshots', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('type', 100);
            $t->text('data');
            $t->string('label', 255)->null();
            $t->boolean('auto_generated')->default(false);
            $t->string('user_id', 36)->null();
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->index(['type']);
            $t->index(['created_at']);
        });
    }
}
