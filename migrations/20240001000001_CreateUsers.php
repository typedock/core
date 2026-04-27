<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

final class CreateUsers extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('email', 254);
            $t->string('password_hash', 255);
            $t->string('name', 255);
            $t->string('display_name', 120)->null();
            $t->string('slug', 120)->null();
            $t->text('bio')->null();
            $t->string('avatar_media_id', 36)->null();
            $t->string('website_url', 500)->null();
            $t->text('social_links')->null();
            $t->string('role', 20)->default('contributor');
            $t->string('avatar_path', 1000)->null();
            $t->string('two_factor_secret', 255)->null();
            $t->boolean('two_factor_enabled')->default(false);
            $t->integer('login_attempts')->default(0);
            $t->datetime('locked_until')->null();
            $t->datetime('last_login_at')->null();
            $t->datetime('created_at')->useCurrent();
            $t->datetime('updated_at');
            $t->primary(['id']);
            $t->unique(['email']);
            $t->unique(['slug']);
        });

        $schema->create('sessions', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('user_id', 36);
            $t->string('token_hash', 255);
            $t->string('ip_address', 45)->null();
            $t->string('user_agent', 500)->null();
            $t->boolean('two_factor_verified')->default(false);
            $t->datetime('expires_at');
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->index(['token_hash']);
            $t->foreign('user_id', 'users', 'id')->cascadeOnDelete();
        });

        $schema->create('password_resets', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('user_id', 36);
            $t->string('token_hash', 255);
            $t->datetime('expires_at');
            $t->datetime('used_at')->null();
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->foreign('user_id', 'users', 'id')->cascadeOnDelete();
        });

        $schema->create('api_keys', function (Blueprint $t) {
            $t->string('id', 36);
            $t->string('user_id', 36);
            $t->string('name', 255);
            $t->string('key_hash', 255);
            $t->string('key_prefix', 8);
            $t->text('permissions')->null();
            $t->datetime('expires_at')->null();
            $t->datetime('last_used_at')->null();
            $t->datetime('created_at')->useCurrent();
            $t->primary(['id']);
            $t->index(['key_prefix']);
            $t->foreign('user_id', 'users', 'id')->cascadeOnDelete();
        });
    }
}
