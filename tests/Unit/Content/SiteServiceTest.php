<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Content\SiteService;

final class SiteServiceTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE site_options (
            key_name TEXT NOT NULL PRIMARY KEY,
            value TEXT NOT NULL,
            group_name TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE media (
            id TEXT NOT NULL PRIMARY KEY,
            path TEXT NOT NULL
        )');
    }

    public function testFaviconUrlReturnsNullWhenNotConfigured(): void
    {
        $site = new SiteService($this->pdo);
        $this->assertNull($site->faviconId);
        $this->assertNull($site->faviconUrl);
    }

    public function testFaviconUrlResolvesFromMediaTable(): void
    {
        $mediaId = '018f4a1a-7b3b-7a32-9c12-000000000001';
        $stmt = $this->pdo->prepare("INSERT INTO site_options (key_name, value, group_name) VALUES (?, ?, 'general')");
        $stmt->execute(['site.favicon_id', json_encode($mediaId)]);

        $stmtMedia = $this->pdo->prepare("INSERT INTO media (id, path) VALUES (?, ?)");
        $stmtMedia->execute([$mediaId, '2026/08/icon.png']);

        $site = new SiteService($this->pdo);
        $this->assertSame($mediaId, $site->faviconId);
        $this->assertNotNull($site->faviconUrl);
        $this->assertStringEndsWith('/uploads/2026/08/icon.png', $site->faviconUrl);
    }

    public function testFaviconUrlPreservesAbsoluteUrls(): void
    {
        $mediaId = '018f4a1a-7b3b-7a32-9c12-000000000002';
        $stmt = $this->pdo->prepare("INSERT INTO site_options (key_name, value, group_name) VALUES (?, ?, 'general')");
        $stmt->execute(['site.favicon_id', json_encode($mediaId)]);

        $stmtMedia = $this->pdo->prepare("INSERT INTO media (id, path) VALUES (?, ?)");
        $stmtMedia->execute([$mediaId, 'https://cdn.example.com/favicon.png']);

        $site = new SiteService($this->pdo);
        $this->assertSame('https://cdn.example.com/favicon.png', $site->faviconUrl);
    }
}
