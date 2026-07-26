<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

/**
 * Background job queue.
 *
 * Rows are claimed with an optimistic conditional UPDATE rather than
 * `SELECT ... FOR UPDATE SKIP LOCKED`, because SQLite is a first-class driver
 * here and has neither. `lease_until` is what makes a crashed worker's job
 * recoverable: once the lease expires any worker may re-claim the row.
 *
 * There is no `done` status — a finished one-shot job is deleted, and a
 * recurring job is re-armed to `pending`. The table therefore stays the size
 * of the outstanding work plus whatever has permanently failed, which is the
 * only history an operator needs to act on.
 */
final class CreateJobs extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('jobs', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('queue', 50)->default('default');
            $t->string('handler', 100);
            $t->text('payload')->null();
            $t->string('status', 16)->default('pending');  // pending|running|failed
            $t->string('batch_id', 36)->null();
            $t->integer('attempts')->default(0);
            $t->datetime('run_after')->null();
            $t->datetime('lease_until')->null();
            $t->string('worker_id', 64)->null();
            $t->text('last_error')->null();
            $t->datetime('created_at');
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->index(['queue', 'status', 'run_after']);
            $t->index(['batch_id', 'status']);
        });
    }
}
