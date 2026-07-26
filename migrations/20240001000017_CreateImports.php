<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

/**
 * Content import bookkeeping.
 *
 * `imports` holds one row per import run — enough to resume it after a
 * timeout (`processed`) and to stop two workers running the same one
 * (`lease_until`).
 *
 * The columns on `posts` are what make an import re-runnable: the unique
 * index on (external_source, external_id) is simultaneously the duplicate
 * guard and the persisted old-id → new-id map, so nothing has to hold tens of
 * thousands of mappings in memory. `external_parent_id` parks a parent
 * reference that has not arrived yet — WXR is not topologically sorted, so a
 * child page routinely appears before its parent.
 *
 * The column is deliberately called `processed`, not `cursor`: CURSOR is a
 * reserved word in both MySQL and PostgreSQL.
 */
final class CreateImports extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('imports', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('importer', 50);
            $t->string('source_name', 255)->null();
            $t->string('source_file', 1000)->null();
            $t->string('status', 16)->default('ready');  // ready|running|done|failed|cancelled
            $t->integer('processed')->default(0);
            $t->datetime('lease_until')->null();
            $t->text('options')->null();                 // JSON
            $t->text('summary')->null();                 // JSON: counts + warnings
            $t->string('created_by', 36)->null();
            $t->datetime('created_at');
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->index(['status']);
        });

        $schema->execute('ALTER TABLE posts ADD COLUMN external_source VARCHAR(50) NULL');
        $schema->execute('ALTER TABLE posts ADD COLUMN external_id VARCHAR(255) NULL');
        $schema->execute('ALTER TABLE posts ADD COLUMN external_parent_id VARCHAR(255) NULL');
        $schema->execute('ALTER TABLE posts ADD COLUMN import_batch_id VARCHAR(36) NULL');
        $schema->execute('CREATE UNIQUE INDEX ux_posts_external ON posts (external_source, external_id)');
        $schema->execute('CREATE INDEX ix_posts_import_batch ON posts (import_batch_id)');
    }
}
