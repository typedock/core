<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration;

use PDO;
use TypeDock\Core\Migration\Grammar\Grammar;

/**
 * Top-level schema operations used by migrations. Thin wrapper around
 * a PDO connection plus a driver-specific Grammar.
 */
final class Schema
{
    public function __construct(
        public readonly PDO $pdo,
        public readonly string $driver,
        public readonly Grammar $grammar,
    ) {
    }

    public function create(string $table, callable $callback): void
    {
        $blueprint = new Blueprint($table, $this->driver);
        $callback($blueprint);
        foreach ($this->grammar->compileCreate($blueprint) as $sql) {
            $this->pdo->exec($sql);
        }
    }

    public function drop(string $table): void
    {
        $this->pdo->exec($this->grammar->compileDrop($table));
    }

    public function dropIfExists(string $table): void
    {
        $this->pdo->exec($this->grammar->compileDropIfExists($table));
    }

    public function hasTable(string $table): bool
    {
        [$sql, $params] = match ($this->driver) {
            'sqlite' => [
                'SELECT name FROM sqlite_master WHERE type = ? AND name = ?',
                ['table', $table],
            ],
            'pgsql'  => [
                'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = ANY(current_schemas(false)) AND tablename = ?',
                [$table],
            ],
            default  => [
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                [$table],
            ],
        };

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    public function execute(string $sql): void
    {
        $this->pdo->exec($sql);
    }
}
