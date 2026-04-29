<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

/**
 * Locale registry — used by the multilingual support in `src/Locale/` to
 * enumerate available locales and resolve the default. Multilang was
 * promoted from a module to Core; the table was previously bundled with
 * the now-removed Module migration.
 */
final class CreateLocales extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('locales', function (Blueprint $t) {
            $t->string('code', 10);
            $t->string('name', 100);
            $t->integer('is_default')->default(0);
            $t->integer('is_active')->default(1);
            $t->integer('sort_order')->default(0);
            $t->datetime('created_at')->useCurrent();
            $t->primary(['code']);
        });
    }
}
