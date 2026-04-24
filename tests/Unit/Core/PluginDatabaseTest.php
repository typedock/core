<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\PluginDatabase;

final class PluginDatabaseTest extends TestCase
{
    private \PDO $pdo;
    private PluginDatabase $db;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO sqlite driver not available in this environment.');
        }
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE plugin_example_items (id TEXT PRIMARY KEY, name TEXT, status TEXT, position INTEGER)'
        );

        $this->db = new PluginDatabase($this->pdo, 'example');
    }

    public function testInsertFindUpdateDelete(): void
    {
        $id = $this->db->insert('items', ['name' => 'first', 'status' => 'on', 'position' => 1]);
        $this->assertNotSame('', $id);

        $row = $this->db->find('items', $id);
        $this->assertSame('first', $row['name']);

        $this->assertTrue($this->db->update('items', $id, ['name' => 'renamed']));
        $this->assertSame('renamed', $this->db->find('items', $id)['name']);

        $this->assertSame(1, $this->db->count('items'));
        $this->assertTrue($this->db->delete('items', $id));
        $this->assertNull($this->db->find('items', $id));
    }

    public function testListOrderByAllowsSafeFormat(): void
    {
        $this->db->insert('items', ['name' => 'a', 'status' => 'on', 'position' => 2]);
        $this->db->insert('items', ['name' => 'b', 'status' => 'on', 'position' => 1]);

        $rows = $this->db->list('items', [], ['order_by' => 'position ASC']);
        $this->assertSame('b', $rows[0]['name']);
    }

    public function testListRejectsSqlInjectionInOrderBy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->list('items', [], ['order_by' => 'id; DROP TABLE plugin_example_items']);
    }

    public function testInsertRejectsBadColumnName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->insert('items', ['name; --' => 'bad']);
    }

    public function testListRejectsBadConditionColumn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->list('items', ['1=1 OR name' => 'x']);
    }

    public function testTableNameRejectsTraversal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->db->find('items; DROP TABLE users', 'id');
    }
}
