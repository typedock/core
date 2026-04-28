<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateExternalSources extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('external_sources', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('slug', 120);
            $t->string('name', 255);
            $t->string('provider', 50)->default('contentful');
            $t->string('status', 20)->default('active');
            $t->text('config')->null();
            $t->text('field_mapping')->null();
            $t->text('detail_template')->null();
            $t->integer('cache_ttl_seconds')->default(600);
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->unique(['slug']);
            $t->index(['status', 'slug']);
        });

        $schema->create('external_source_credentials', function (Blueprint $t) {
            $t->string('source_id', 36);
            $t->text('payload');
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at');
            $t->primary(['source_id']);
            $t->foreign('source_id', 'external_sources', 'id')->cascadeOnDelete();
        });
    }
}
