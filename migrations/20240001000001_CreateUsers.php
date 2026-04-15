<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsers extends AbstractMigration
{
    public function change(): void
    {
        // users table
        $users = $this->table('users', ['id' => false, 'primary_key' => ['id']]);
        $users
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('email', 'string', ['limit' => 254])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('role', 'string', ['limit' => 20, 'default' => 'contributor'])
            ->addColumn('avatar_path', 'string', ['limit' => 1000, 'null' => true, 'default' => null])
            ->addColumn('two_factor_secret', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('two_factor_enabled', 'boolean', ['default' => false])
            ->addColumn('login_attempts', 'integer', ['default' => 0])
            ->addColumn('locked_until', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('last_login_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', [])
            ->addIndex(['email'], ['unique' => true])
            ->create();

        // sessions table
        $sessions = $this->table('sessions', ['id' => false, 'primary_key' => ['id']]);
        $sessions
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('user_id', 'string', ['limit' => 36])
            ->addColumn('token_hash', 'string', ['limit' => 255])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('user_agent', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addColumn('two_factor_verified', 'boolean', ['default' => false])
            ->addColumn('expires_at', 'datetime', [])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['token_hash'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        // password_resets table
        $passwordResets = $this->table('password_resets', ['id' => false, 'primary_key' => ['id']]);
        $passwordResets
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('user_id', 'string', ['limit' => 36])
            ->addColumn('token_hash', 'string', ['limit' => 255])
            ->addColumn('expires_at', 'datetime', [])
            ->addColumn('used_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();

        // api_keys table
        $apiKeys = $this->table('api_keys', ['id' => false, 'primary_key' => ['id']]);
        $apiKeys
            ->addColumn('id', 'string', ['limit' => 36])
            ->addColumn('user_id', 'string', ['limit' => 36])
            ->addColumn('name', 'string', ['limit' => 255])
            ->addColumn('key_hash', 'string', ['limit' => 255])
            ->addColumn('key_prefix', 'string', ['limit' => 8])
            ->addColumn('permissions', 'text', ['null' => true, 'default' => null])
            ->addColumn('expires_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('last_used_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['key_prefix'])
            ->addForeignKey('user_id', 'users', 'id', ['delete' => 'CASCADE', 'update' => 'NO_ACTION'])
            ->create();
    }
}
