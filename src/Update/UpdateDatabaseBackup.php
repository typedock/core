<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class UpdateDatabaseBackup
{
    /**
     * @param array<string, mixed> $databaseConfig
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly array $databaseConfig,
        private readonly string $root,
    ) {}

    /**
     * @return array<string, string>
     */
    public function create(string $backupDir): array
    {
        if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new \RuntimeException('Unable to create the pre-update backup directory.');
        }

        $driver = strtolower((string) ($this->databaseConfig['driver'] ?? 'mysql'));
        if ($driver === 'sqlite') {
            $source = (string) ($this->databaseConfig['sqlite_path'] ?? $this->root . '/storage/database.sqlite');
            if (!is_file($source)) {
                throw new \RuntimeException('SQLite database file was not found.');
            }
            $this->pdo->exec('PRAGMA wal_checkpoint(FULL)');
            $destination = $backupDir . '/database.sqlite';
            if (!copy($source, $destination)) {
                throw new \RuntimeException('Unable to copy the SQLite database backup.');
            }
            return ['type' => 'sqlite', 'source' => $source, 'path' => $destination];
        }

        $path = $backupDir . '/database.jsonl.gz';
        $this->createPortableSnapshot($path, $driver);
        return ['type' => 'portable', 'driver' => $driver, 'path' => $path];
    }

    /**
     * @param array<string, string> $backup
     */
    public function restore(array $backup, string $failedDir): void
    {
        if (($backup['type'] ?? '') === 'sqlite') {
            $source = (string) ($backup['source'] ?? '');
            $saved = (string) ($backup['path'] ?? '');
            if (!is_file($saved) || $source === '') {
                throw new \RuntimeException('SQLite rollback backup is missing.');
            }
            if (!is_dir($failedDir) && !mkdir($failedDir, 0775, true) && !is_dir($failedDir)) {
                throw new \RuntimeException('Unable to create the rollback holding directory.');
            }
            foreach ([$source, $source . '-wal', $source . '-shm'] as $live) {
                if (is_file($live)) {
                    @rename($live, $failedDir . '/' . basename($live));
                }
            }
            if (!copy($saved, $source)) {
                throw new \RuntimeException('Unable to restore the SQLite database.');
            }
            return;
        }

        if (($backup['type'] ?? '') === 'portable') {
            $path = (string) ($backup['path'] ?? '');
            if (!is_file($path)) {
                throw new \RuntimeException('Database rollback snapshot is unavailable.');
            }
            $this->restorePortableSnapshot($path, (string) ($backup['driver'] ?? 'mysql'));
            return;
        }

        throw new \RuntimeException('Unknown database backup type.');
    }

    private function createPortableSnapshot(string $path, string $driver): void
    {
        $schemaDriver = $driver === 'libsql' ? 'sqlite' : $driver;
        $out = gzopen($path, 'wb6');
        if ($out === false) {
            throw new \RuntimeException('Unable to create the database snapshot.');
        }
        try {
            gzwrite($out, json_encode(['schema_version' => 1, 'driver' => $driver], JSON_THROW_ON_ERROR) . "\n");
            foreach ($this->listTables($schemaDriver) as $table) {
                gzwrite($out, json_encode(['type' => 'table', 'name' => $table], JSON_THROW_ON_ERROR) . "\n");
                $stmt = $this->pdo->query('SELECT * FROM ' . $this->quoteIdentifier($schemaDriver, $table));
                if ($stmt === false) {
                    throw new \RuntimeException("Unable to read table {$table} for backup.");
                }
                while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                    $values = [];
                    foreach ($row as $column => $value) {
                        $values[(string) $column] = $value === null
                            ? null
                            : base64_encode((string) $value);
                    }
                    gzwrite($out, json_encode(
                        ['type' => 'row', 'table' => $table, 'values' => $values],
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    ) . "\n");
                }
            }
        } finally {
            gzclose($out);
        }
    }

    private function restorePortableSnapshot(string $path, string $driver): void
    {
        $schemaDriver = $driver === 'libsql' ? 'sqlite' : $driver;
        $in = gzopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Unable to open the database rollback snapshot.');
        }

        $foreignKeysDisabled = false;
        try {
            if ($schemaDriver === 'mysql') {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $foreignKeysDisabled = true;
            } elseif ($schemaDriver === 'pgsql') {
                try {
                    $this->pdo->exec('SET session_replication_role = replica');
                    $foreignKeysDisabled = true;
                } catch (\Throwable) {
                    // Some shared-hosting PostgreSQL roles cannot change this;
                    // continue and let constraint errors surface explicitly.
                }
            }

            $cleared = [];
            while (!gzeof($in)) {
                $line = gzgets($in);
                if ($line === false || trim($line) === '') {
                    continue;
                }
                $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($record) || !isset($record['type'])) {
                    continue;
                }
                $table = (string) ($record['name'] ?? $record['table'] ?? '');
                if ($table === '') {
                    continue;
                }
                if (($record['type'] ?? '') === 'table') {
                    $this->pdo->exec('DELETE FROM ' . $this->quoteIdentifier($schemaDriver, $table));
                    $cleared[$table] = true;
                    continue;
                }
                if (($record['type'] ?? '') !== 'row' || !isset($cleared[$table])) {
                    continue;
                }
                $encoded = $record['values'] ?? null;
                if (!is_array($encoded) || $encoded === []) {
                    continue;
                }
                $columns = array_keys($encoded);
                $sql = 'INSERT INTO ' . $this->quoteIdentifier($schemaDriver, $table)
                    . ' (' . implode(', ', array_map(
                        fn(string $column): string => $this->quoteIdentifier($schemaDriver, $column),
                        $columns,
                    )) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
                $values = [];
                foreach ($encoded as $value) {
                    if ($value === null) {
                        $values[] = null;
                        continue;
                    }
                    $decoded = base64_decode((string) $value, true);
                    if ($decoded === false) {
                        throw new \RuntimeException('Database snapshot contains malformed row data.');
                    }
                    $values[] = $decoded;
                }
                $this->pdo->prepare($sql)->execute($values);
            }
        } finally {
            gzclose($in);
            if ($foreignKeysDisabled && $schemaDriver === 'mysql') {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($foreignKeysDisabled && $schemaDriver === 'pgsql') {
                $this->pdo->exec('SET session_replication_role = origin');
            }
        }
    }

    /**
     * @return list<string>
     */
    private function listTables(string $driver): array
    {
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            'pgsql' => "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename",
            default => 'SHOW TABLES',
        };
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Unable to enumerate database tables for backup.');
        }
        $tables = [];
        while (($value = $stmt->fetchColumn()) !== false) {
            $tables[] = (string) $value;
        }
        return $tables;
    }

    private function quoteIdentifier(string $driver, string $identifier): string
    {
        return $driver === 'mysql'
            ? '`' . str_replace('`', '``', $identifier) . '`'
            : '"' . str_replace('"', '""', $identifier) . '"';
    }
}
