<?php
declare(strict_types=1);

namespace TypeDock\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\Migration\Migrator;

/**
 * Boots a fresh SQLite DB and runs every migration through the same PHP API
 * used by Installer::runMigrations() to ensure the schema applies cleanly
 * across all configured drivers (SQLite as the canary here).
 */
final class MigrationsTest extends TestCase
{
    private string $sqlitePath;

    protected function setUp(): void
    {
        // Per-test fresh DB so schema state is isolated.
        $this->sqlitePath = sys_get_temp_dir() . '/typedock-mig-' . bin2hex(random_bytes(6)) . '.sqlite';
        putenv('DB_DRIVER=sqlite');
        putenv('DB_SQLITE_PATH=' . $this->sqlitePath);
        $_ENV['DB_DRIVER']      = 'sqlite';
        $_ENV['DB_SQLITE_PATH'] = $this->sqlitePath;
    }

    protected function tearDown(): void
    {
        if (is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
    }

    public function testAllMigrationsRunOnSqliteAndCreateExpectedTables(): void
    {
        $pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $migrator = new Migrator($pdo, 'sqlite', TYPEDOCK_ROOT . '/migrations');
        $result = $migrator->migrate();

        $this->assertSame([], $result['errors'], 'Migration errors: ' . json_encode($result['errors']));

        $rows   = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        $tables = array_map('strval', $rows);

        $expected = [
            'users',
            'sessions',
            'password_resets',
            'api_keys',
            'pages',
            'page_revisions',
            'categories',
            'tags',
            'page_categories',
            'page_tags',
            'media',
            'menus',
            'menu_items',
            'site_options',
            'seo_meta',
            'slot_placements',
            'change_log',
            'snapshots',
            'collections',
            'collection_items',
            'backups',
            'locales',
            'migrations',
        ];

        foreach ($expected as $name) {
            $this->assertContains(
                $name,
                $tables,
                "Expected table \"{$name}\" to exist after migrations. Got: " . implode(', ', $tables)
            );
        }

        $pageColumns = $pdo->query("PRAGMA table_info('pages')")->fetchAll(\PDO::FETCH_ASSOC);
        $pageColumnNames = array_map('strval', array_column($pageColumns, 'name'));
        $this->assertContains('body_markdown', $pageColumnNames);

        $revisionColumns = $pdo->query("PRAGMA table_info('page_revisions')")->fetchAll(\PDO::FETCH_ASSOC);
        $revisionColumnNames = array_map('strval', array_column($revisionColumns, 'name'));
        $this->assertContains('body_markdown', $revisionColumnNames);
    }

    public function testRedirectPluginMigrationCreatesRedirectsTable(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $runner = new \TypeDock\Plugin\Util\PluginMigrationRunner($pdo, 'redirect');
        $runner->runFromDirectory(TYPEDOCK_ROOT . '/plugins/redirect/migrations');

        $tables = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('redirects', $tables);
        $this->assertContains('plugin_migrations', $tables);
    }

    public function testFormPluginMigrationCreatesOwnedTables(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $runner = new \TypeDock\Plugin\Util\PluginMigrationRunner($pdo, 'form');
        $runner->runFromDirectory(TYPEDOCK_ROOT . '/plugins/form/migrations');

        $tables = $pdo
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('plugin_form_forms', $tables);
        $this->assertContains('plugin_form_submissions', $tables);
        $this->assertContains('plugin_form_antispam_log', $tables);
        $this->assertContains('plugin_migrations', $tables);
    }
}
