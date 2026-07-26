<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

/**
 * Lets a media row exist before its file does.
 *
 * The importer reserves a row — and therefore a final storage path and URL —
 * while parsing, so the post body can be written once with the URL the image
 * will eventually have. `status` is what distinguishes a reserved row from a
 * fetched one; the download job flips it.
 *
 * Deduplication keys on `source_hash` rather than `source_url` because a
 * unique index over a long VARCHAR exceeds MySQL's key length, and index
 * prefixes (`source_url(255)`) are MySQL-only syntax. NULLs stay distinct on
 * all three drivers, so ordinary uploads are unaffected.
 */
final class AddMediaImportColumns extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->execute('ALTER TABLE media ADD COLUMN source_url VARCHAR(2000) NULL');
        $schema->execute('ALTER TABLE media ADD COLUMN source_hash VARCHAR(64) NULL');
        $schema->execute("ALTER TABLE media ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'ready'");
        $schema->execute('ALTER TABLE media ADD COLUMN import_batch_id VARCHAR(36) NULL');
        $schema->execute('CREATE UNIQUE INDEX ux_media_source_hash ON media (source_hash)');
        $schema->execute('CREATE INDEX ix_media_import_batch ON media (import_batch_id)');
    }
}
