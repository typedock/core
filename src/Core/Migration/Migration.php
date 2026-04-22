<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration;

/**
 * Base class for all TypeDock migrations. Forward-only: only up() is defined.
 * Rollback is handled by restoring from backups, not by schema reversal.
 */
abstract class Migration
{
    abstract public function up(Schema $schema): void;
}
