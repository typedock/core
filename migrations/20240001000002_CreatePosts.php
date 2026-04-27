<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreatePosts extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('posts', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('slug', 1000);
            $t->string('title', 500);
            $t->text('body')->null();
            $t->text('body_markdown')->null();
            $t->text('excerpt')->null();
            $t->string('post_type', 20)->default('post');
            $t->string('status', 20)->default('draft');
            $t->string('author_id', 36)->null();
            $t->string('parent_id', 36)->null();
            $t->string('template', 255)->null();
            $t->string('layout', 100)->null();
            $t->string('locale', 10)->default('en');
            $t->string('translation_group_id', 36)->null();
            $t->datetime('published_at')->null();
            $t->datetime('scheduled_at')->null();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->index(['slug'], ['slug' => 255]);
            $t->index(['translation_group_id']);
            $t->index(['post_type', 'status', 'published_at']);
            $t->foreign('author_id', 'users', 'id')->nullOnDelete();
            $t->foreign('parent_id', 'posts', 'id')->nullOnDelete();
        });

        $schema->create('post_revisions', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('post_id', 36);
            $t->string('title', 500);
            $t->text('body')->null();
            $t->text('body_markdown')->null();
            $t->string('author_id', 36)->null();
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->foreign('post_id', 'posts', 'id')->cascadeOnDelete();
            $t->foreign('author_id', 'users', 'id')->nullOnDelete();
        });
    }
}
