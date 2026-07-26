<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

/**
 * The permalink a post had on the site it was imported from.
 *
 * Two features need it and neither can work without it being persisted: the
 * redirect map (old URL → new URL, so the old site's links keep working), and
 * rewriting body links that point back at the old site. Both run after every
 * document has landed, because only then is it known whether a link's target
 * became a post or a page and what slug it finally got.
 */
final class AddPostExternalUrl extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->execute('ALTER TABLE posts ADD COLUMN external_url VARCHAR(2000) NULL');
    }
}
