<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Util;

/**
 * Idempotent SQL migration runner for plugins. Plugins ship `*.sql` files
 * and call `$ctx->migrate()` at register() time; applied files are tracked
 * in a shared `plugin_migrations` table so re-running at boot is a no-op.
 *
 * Rationale: plugin authors write raw SQL for their own tables (we do not
 * wrap the Core schema builder here). One SQL file = one atomic statement
 * block; multiple statements inside a single file are allowed and split on
 * semicolon at line end. Cross-DB portability is the plugin author's
 * problem — Core just provides the runner.
 */
class PluginMigrationRunner
{
    private const TRACKING_TABLE = 'plugin_migrations';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $pluginSlug
    ) {}

    /**
     * Run every SQL file in the given directory whose name hasn't yet been
     * recorded for this plugin. Files are applied in filename order — plugin
     * authors should name them `0001_*.sql`, `0002_*.sql`, etc.
     */
    public function runFromDirectory(string $dir): void
    {
        $this->ensureTrackingTable();
        if (!is_dir($dir)) {
            return;
        }
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file);
            if ($this->isApplied($name)) {
                continue;
            }
            $this->applyFile($file);
            $this->markApplied($name);
        }
    }

    /**
     * Run the list of migration files explicitly, in the order given. Useful
     * when a plugin wants to bundle migrations with its code rather than its
     * filesystem directory.
     *
     * @param array<int, string> $files Absolute paths
     */
    public function runFiles(array $files): void
    {
        $this->ensureTrackingTable();
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $name = basename($file);
            if ($this->isApplied($name)) {
                continue;
            }
            $this->applyFile($file);
            $this->markApplied($name);
        }
    }

    private function ensureTrackingTable(): void
    {
        $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = match ($driver) {
            'sqlite' => 'CREATE TABLE IF NOT EXISTS ' . self::TRACKING_TABLE . ' (
                plugin_slug TEXT NOT NULL,
                migration_name TEXT NOT NULL,
                applied_at TEXT NOT NULL,
                PRIMARY KEY (plugin_slug, migration_name)
            )',
            'pgsql'  => 'CREATE TABLE IF NOT EXISTS ' . self::TRACKING_TABLE . ' (
                plugin_slug VARCHAR(64) NOT NULL,
                migration_name VARCHAR(255) NOT NULL,
                applied_at TIMESTAMP NOT NULL,
                PRIMARY KEY (plugin_slug, migration_name)
            )',
            default  => 'CREATE TABLE IF NOT EXISTS ' . self::TRACKING_TABLE . ' (
                plugin_slug VARCHAR(64) NOT NULL,
                migration_name VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (plugin_slug, migration_name)
            ) CHARSET=utf8mb4',
        };
        $this->pdo->exec($sql);
    }

    private function isApplied(string $name): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . self::TRACKING_TABLE . ' WHERE plugin_slug = ? AND migration_name = ?'
        );
        $stmt->execute([$this->pluginSlug, $name]);
        return $stmt->fetchColumn() !== false;
    }

    private function markApplied(string $name): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . self::TRACKING_TABLE . ' (plugin_slug, migration_name, applied_at) VALUES (?, ?, ?)'
        )->execute([
            $this->pluginSlug,
            $name,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function applyFile(string $file): void
    {
        $sql = (string) file_get_contents($file);
        // Strip line comments so we can split reliably on `;\n`. Inline `--`
        // inside strings would confuse us, but plugin migrations rarely need
        // that — it's a conscious simplification.
        $sql       = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = preg_split('/;\s*(?:\n|$)/', $sql) ?: [];
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            $this->pdo->exec($stmt);
        }
    }
}
