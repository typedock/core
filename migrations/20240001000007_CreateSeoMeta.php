<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateSeoMeta extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('seo_meta', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('target_type', 50);
            $t->string('target_id', 36)->null();
            $t->string('seo_title', 500)->null();
            $t->text('meta_description')->null();
            $t->string('canonical_url', 2000)->null();
            $t->string('robots', 100)->null();
            $t->string('og_title', 500)->null();
            $t->text('og_description')->null();
            $t->string('og_image_id', 36)->null();
            $t->string('twitter_card', 50)->null();
            $t->string('focus_keyword', 255)->null();
            $t->string('schema_type', 100)->null();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->unique(['target_type', 'target_id']);
            $t->foreign('og_image_id', 'media', 'id')->nullOnDelete();
        });
    }
}
