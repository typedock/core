<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use TypeDock\Auth\ApiKeyService;

final class ApiKeyServiceTest extends TestCase
{
    private \PDO $pdo;
    private ApiKeyService $service;
    private string $userId = '00000000-0000-7000-8000-000000000001';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Minimal api_keys table mirroring the migration's columns ApiKeyService touches.
        $this->pdo->exec(
            'CREATE TABLE users (
                id TEXT PRIMARY KEY,
                email TEXT NOT NULL,
                name TEXT NOT NULL,
                role TEXT NOT NULL
            )'
        );
        $this->pdo->prepare('INSERT INTO users (id, email, name, role) VALUES (?, ?, ?, ?)')
            ->execute([$this->userId, 'admin@example.com', 'Admin', 'admin']);

        $this->pdo->exec(
            'CREATE TABLE api_keys (
                id TEXT PRIMARY KEY,
                user_id TEXT NOT NULL,
                name TEXT NOT NULL,
                key_hash TEXT NOT NULL,
                key_prefix TEXT NOT NULL,
                permissions TEXT NULL,
                expires_at TEXT NULL,
                last_used_at TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );

        $this->service = new ApiKeyService($this->pdo);
    }

    public function testCreateProducesProperlyFormattedToken(): void
    {
        $result = $this->service->create($this->userId, 'CI Token');

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('id', $result);

        // td_{8 hex}_{32 hex}
        $this->assertMatchesRegularExpression(
            '/^td_[a-f0-9]{8}_[a-f0-9]{32}$/',
            $result['key'],
            'Plaintext key must follow the td_{prefix}_{random} format.'
        );
    }

    public function testCreateStoresHashNotPlaintext(): void
    {
        $result = $this->service->create($this->userId, 'CI Token');

        $row = $this->pdo->query('SELECT key_hash, key_prefix FROM api_keys')->fetch();
        $this->assertNotFalse($row);
        $this->assertNotSame($result['key'], $row['key_hash'], 'Stored value must not be plaintext.');
        $this->assertSame(hash('sha256', $result['key']), $row['key_hash']);

        // Prefix in DB matches the prefix segment of the plaintext token.
        [, $prefix] = explode('_', $result['key']);
        $this->assertSame($prefix, $row['key_prefix']);
    }

    public function testValidateAcceptsIssuedTokenAndRejectsMalformed(): void
    {
        $issued = $this->service->create($this->userId, 'CI Token');

        $ok = $this->service->validate($issued['key']);
        $this->assertNotNull($ok);
        $this->assertSame($this->userId, $ok['user_id']);

        $this->assertNull($this->service->validate('not-a-token'));
        $this->assertNull($this->service->validate('td_deadbeef_' . str_repeat('0', 32)));
    }

    public function testValidateEnforcesPermission(): void
    {
        $issued = $this->service->create($this->userId, 'Scoped', ['posts:read']);

        $this->assertNotNull($this->service->validate($issued['key'], 'posts:read'));
        $this->assertNull($this->service->validate($issued['key'], 'media:upload'));
    }

    public function testUnscopedKeyInheritsUserRolePermissions(): void
    {
        $issued = $this->service->create($this->userId, 'Inherited');

        $this->assertNotNull($this->service->validate($issued['key'], 'settings:manage'));
        $this->assertNotNull($this->service->validate($issued['key'], 'media:upload'));
    }
}
