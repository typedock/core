<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

/**
 * Featured images, and the external keys that make them resolvable.
 *
 * A featured image is an id-to-id reference carried as an untyped string, and
 * the asset it points at routinely appears *after* the post that uses it. The
 * classic fix — accumulate old-id → new-id in memory and remap at the end —
 * cannot survive an importer that resumes across requests, which is exactly
 * how WordPress's own streaming fork ended up with featured images silently
 * not working at all.
 *
 * So the reference is parked on the row (`posts.external_featured_id`) the
 * same way an unresolved parent is, and assets get their own stable key
 * (`media.external_source` + `external_id`). Whichever arrives first, the
 * resolve pass at the end of the import finds the pair — and counts the ones
 * it could not, because a missing featured image shows no broken picture, only
 * an empty thumbnail nobody notices.
 */
final class AddImportFeaturedImages extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->execute('ALTER TABLE posts ADD COLUMN external_featured_id VARCHAR(255) NULL');
        $schema->execute('ALTER TABLE media ADD COLUMN external_source VARCHAR(50) NULL');
        $schema->execute('ALTER TABLE media ADD COLUMN external_id VARCHAR(255) NULL');
        $schema->execute('CREATE UNIQUE INDEX ux_media_external ON media (external_source, external_id)');
    }
}
