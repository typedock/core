<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration;

use PDO;
use RuntimeException;
use TypeDock\Core\Migration\Grammar\Grammar;
use TypeDock\Core\Migration\Grammar\MySqlGrammar;
use TypeDock\Core\Migration\Grammar\PostgresGrammar;
use TypeDock\Core\Migration\Grammar\SqliteGrammar;

/**
 * Forward-only migration runner. Discovers `{version}_{ClassName}.php` files,
 * executes those not yet recorded in the `migrations` tracking table, and
 * writes a row per applied migration.
 *
 * Designed to be called from either CLI or a web-based installer.
 */
final class Migrator
{
    private const TRACKING_TABLE = 'migrations';

    private Schema $schema;

    public function __construct(
        private readonly PDO $pdo,
        string $driver,
        private readonly string $migrationsPath,
    ) {
        $grammar = self::grammarFor($driver);
        $this->schema = new Schema($pdo, $driver, $grammar);
    }

    public static function grammarFor(string $driver): Grammar
    {
        return match ($driver) {
            'mysql' => new MySqlGrammar(),
            'pgsql' => new PostgresGrammar(),
            'sqlite' => new SqliteGrammar(),
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };
    }

    /**
     * Run all pending migrations.
     *
     * @return array{applied: list<array{version:string,name:string}>, errors: list<array{version:string,name:string,message:string}>}
     */
    public function migrate(): array
    {
        $this->ensureTrackingTable();
        $applied = $this->appliedVersions();

        $result = ['applied' => [], 'errors' => []];

        foreach ($this->discover() as $entry) {
            if (isset($applied[$entry['version']])) {
                continue;
            }
            try {
                $this->runOne($entry);
                $result['applied'][] = ['version' => $entry['version'], 'name' => $entry['name']];
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'version' => $entry['version'],
                    'name'    => $entry['name'],
                    'message' => $e->getMessage(),
                ];
                return $result;
            }
        }

        return $result;
    }

    /**
     * Return status of every discovered migration.
     *
     * @return list<array{version:string,name:string,applied_at:?string}>
     */
    public function status(): array
    {
        $this->ensureTrackingTable();
        $applied = $this->appliedVersions();

        $rows = [];
        foreach ($this->discover() as $entry) {
            $rows[] = [
                'version'    => $entry['version'],
                'name'       => $entry['name'],
                'applied_at' => $applied[$entry['version']] ?? null,
            ];
        }
        return $rows;
    }

    public function pendingCount(): int
    {
        $this->ensureTrackingTable();
        $applied = $this->appliedVersions();
        $count = 0;
        foreach ($this->discover() as $entry) {
            if (!isset($applied[$entry['version']])) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @param array{version:string,name:string,path:string,class:string} $entry
     */
    private function runOne(array $entry): void
    {
        require_once $entry['path'];
        if (!class_exists($entry['class'])) {
            throw new RuntimeException("Migration class not found: {$entry['class']} in {$entry['path']}");
        }
        $instance = new $entry['class']();
        if (!$instance instanceof Migration) {
            throw new RuntimeException("Migration class must extend " . Migration::class . ": {$entry['class']}");
        }

        $instance->up($this->schema);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->schema->grammar->quote(self::TRACKING_TABLE)
            . ' (version, name, applied_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$entry['version'], $entry['name'], gmdate('Y-m-d H:i:s')]);
    }

    /**
     * @return list<array{version:string,name:string,path:string,class:string}>
     */
    private function discover(): array
    {
        if (!is_dir($this->migrationsPath)) {
            throw new RuntimeException("Migrations directory not found: {$this->migrationsPath}");
        }

        $entries = [];
        foreach (glob($this->migrationsPath . '/*.php') ?: [] as $file) {
            $base = basename($file, '.php');
            if (!preg_match('/^(\d+)_(.+)$/', $base, $m)) {
                continue;
            }
            $entries[] = [
                'version' => $m[1],
                'name'    => $m[2],
                'path'    => $file,
                'class'   => $m[2],
            ];
        }

        usort($entries, fn($a, $b) => strcmp($a['version'], $b['version']));
        return $entries;
    }

    private function ensureTrackingTable(): void
    {
        if ($this->schema->hasTable(self::TRACKING_TABLE)) {
            return;
        }
        $this->schema->create(self::TRACKING_TABLE, function (Blueprint $t) {
            $t->string('version', 20);
            $t->string('name', 255);
            $t->datetime('applied_at')->useCurrent();
            $t->primary(['version']);
        });
    }

    /**
     * @return array<string,string> version => applied_at
     */
    private function appliedVersions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT version, applied_at FROM ' . $this->schema->grammar->quote(self::TRACKING_TABLE)
        );
        if (!$stmt) {
            return [];
        }
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string) $row['version']] = (string) $row['applied_at'];
        }
        return $out;
    }
}
