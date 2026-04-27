<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateModuleTables extends Migration
{
    public function up(Schema $schema): void
    {
        // ---------------------------------------------------------------
        // Collection module: collections + collection_items
        // ---------------------------------------------------------------
        $schema->create('collections', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('handle', 100);
            $t->string('name', 255);
            $t->text('description')->null();
            $t->text('schema')->null();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->unique(['handle']);
        });

        $schema->create('collection_items', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('collection_id', 36);
            $t->string('slug', 255);
            $t->string('title', 500);
            $t->text('data')->null();
            $t->string('status', 20)->default('published');
            $t->string('locale', 10)->default('en');
            $t->integer('sort_order')->default(0);
            $t->datetime('published_at')->null();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->unique(['collection_id', 'slug'], ['slug' => 191]);
            $t->index(['collection_id', 'status']);
            $t->foreign('collection_id', 'collections', 'id')->cascadeOnDelete();
        });

        // ---------------------------------------------------------------
        // Backup module: backup history
        // ---------------------------------------------------------------
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

        // ---------------------------------------------------------------
        // Multilang module: locales registry
        // ---------------------------------------------------------------
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
