<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class AddExternalSourceDescription extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->execute('ALTER TABLE external_sources ADD COLUMN description TEXT NULL');
    }
}
