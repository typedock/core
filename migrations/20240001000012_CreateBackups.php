<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

/**
 * Backup history table — populated by the `backup` drop-in plugin when a
 * snapshot tarball is created.
 */
final class CreateBackups extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('backups', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('filename', 255);
            $t->bigInteger('size_bytes')->default(0);
            $t->string('kind', 20)->default('full');
            $t->string('note', 500)->null();
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->index(['created_at']);
        });
    }
}
