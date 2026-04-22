<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateChangeLog extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('change_log', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('target_type', 100);
            $t->string('target_id', 36);
            $t->string('action', 50);
            $t->text('changes')->null();
            $t->string('user_id', 36)->null();
            $t->string('user_name', 255)->null();
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->index(['target_type', 'target_id']);
            $t->index(['user_id']);
            $t->index(['created_at']);
        });
    }
}
