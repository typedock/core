<?php
declare(strict_types=1);

namespace TypeDock\Module\Backup;

use Ramsey\Uuid\Uuid;

/**
 * BackupService — produces a timestamped tar.gz containing:
 *   - db.sql.gz   (database dump)
 *   - uploads/    (storage/uploads tree)
 *
 * Database dump is implemented in pure PHP via PDO so it works for MySQL,
 * PostgreSQL and SQLite without requiring mysqldump/pg_dump on the host.
 */
class BackupService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $backupDir,
        private readonly string $uploadsDir
    ) {}

    /**
     * @return array{id: string, filename: string, path: string, size: int}
     */
    public function create(string $note = ''): array
    {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0775, true);
        }

        $stamp   = (new \DateTimeImmutable())->format('Ymd-His');
        $tmpDir  = $this->backupDir . '/.tmp-' . $stamp;
        mkdir($tmpDir, 0775, true);

        // 1. DB dump
        $sqlPath = $tmpDir . '/db.sql';
        file_put_contents($sqlPath, $this->dumpDatabase());

        // gzip the SQL dump
        $sqlGz = $sqlPath . '.gz';
        $this->gzipFile($sqlPath, $sqlGz);
        unlink($sqlPath);

        // 2. Tarball db.sql.gz + uploads/
        $tarPath = $this->backupDir . '/backup-' . $stamp . '.tar';
        $this->buildTar($tarPath, $tmpDir, $this->uploadsDir);

        // 3. gzip tar
        $finalPath = $tarPath . '.gz';
        $this->gzipFile($tarPath, $finalPath);
        unlink($tarPath);
        $this->rmdirRecursive($tmpDir);

        $size     = (int) filesize($finalPath);
        $filename = basename($finalPath);
        $id       = Uuid::uuid7()->toString();

        try {
            $this->pdo->prepare(
                'INSERT INTO backups (id, filename, size_bytes, kind, note, created_at) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $filename,
                $size,
                'full',
                $note !== '' ? $note : null,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // backup history is optional
        }

        return ['id' => $id, 'filename' => $filename, 'path' => $finalPath, 'size' => $size];
    }

    /**
     * Restore from a backup archive. Restores DB (re-runs the SQL) and replaces uploads/.
     */
    public function restore(string $archivePath): void
    {
        if (!is_file($archivePath)) {
            throw new \RuntimeException("Backup archive not found: {$archivePath}");
        }

        $tmp = $this->backupDir . '/.restore-' . bin2hex(random_bytes(4));
        mkdir($tmp, 0775, true);

        // Decompress .tar.gz → .tar
        $tarPath = $tmp . '/archive.tar';
        $this->gunzipFile($archivePath, $tarPath);

        // Extract tar
        $phar = new \PharData($tarPath);
        $phar->extractTo($tmp, null, true);

        // Restore DB
        $sqlGz = $tmp . '/db.sql.gz';
        if (is_file($sqlGz)) {
            $sqlPath = $tmp . '/db.sql';
            $this->gunzipFile($sqlGz, $sqlPath);
            $this->executeSqlFile($sqlPath);
        }

        // Restore uploads
        $uploadsSrc = $tmp . '/uploads';
        if (is_dir($uploadsSrc)) {
            $this->rmdirRecursive($this->uploadsDir);
            mkdir($this->uploadsDir, 0775, true);
            $this->copyTree($uploadsSrc, $this->uploadsDir);
        }

        $this->rmdirRecursive($tmp);
    }

    /** @return array<array<string, mixed>> */
    public function listBackups(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT * FROM backups ORDER BY created_at DESC');
            return $stmt ? $stmt->fetchAll() : [];
        } catch (\Throwable) {
            return [];
        }
    }

    // -----------------------------------------------------------------
    // DB dump (driver-agnostic)
    // -----------------------------------------------------------------

    private function dumpDatabase(): string
    {
        $driver = (string) $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $tables = $this->listTables($driver);

        $out  = "-- TypeDock backup\n-- Driver: {$driver}\n-- Generated: " . date('c') . "\n\n";

        foreach ($tables as $table) {
            $out .= "-- Table: {$table}\n";
            $out .= "DELETE FROM {$this->quoteIdent($driver, $table)};\n";

            $rows = $this->pdo->query('SELECT * FROM ' . $this->quoteIdent($driver, $table));
            if ($rows === false) {
                continue;
            }
            foreach ($rows as $row) {
                $cols = array_map(fn($c) => $this->quoteIdent($driver, (string) $c), array_keys($row));
                $vals = array_map(fn($v) => $this->quoteValue($v), array_values($row));
                $out .= 'INSERT INTO ' . $this->quoteIdent($driver, $table)
                     . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n";
            }
            $out .= "\n";
        }
        return $out;
    }

    /** @return string[] */
    private function listTables(string $driver): array
    {
        $sql = match ($driver) {
            'sqlite' => "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
            'pgsql'  => "SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename",
            default  => 'SHOW TABLES',
        };
        $stmt   = $this->pdo->query($sql);
        $tables = [];
        if ($stmt) {
            foreach ($stmt as $row) {
                $tables[] = (string) array_values($row)[0];
            }
        }
        return $tables;
    }

    private function quoteIdent(string $driver, string $name): string
    {
        return match ($driver) {
            'mysql' => '`' . str_replace('`', '``', $name) . '`',
            default => '"' . str_replace('"', '""', $name) . '"',
        };
    }

    private function quoteValue(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return $this->pdo->quote((string) $v);
    }

    private function executeSqlFile(string $path): void
    {
        $sql = (string) file_get_contents($path);
        // naive split on ";\n" — adequate for our generated dump
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                continue;
            }
            try {
                $this->pdo->exec($stmt);
            } catch (\Throwable $e) {
                error_log('[TypeDock\\Backup] SQL exec failed: ' . $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------
    // gzip + tar
    // -----------------------------------------------------------------

    private function gzipFile(string $src, string $dest): void
    {
        $in  = fopen($src, 'rb');
        $out = gzopen($dest, 'wb9');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Failed to open file for gzip.');
        }
        while (!feof($in)) {
            $buf = fread($in, 8192);
            if ($buf === false) {
                break;
            }
            gzwrite($out, $buf);
        }
        fclose($in);
        gzclose($out);
    }

    private function gunzipFile(string $src, string $dest): void
    {
        $in  = gzopen($src, 'rb');
        $out = fopen($dest, 'wb');
        if ($in === false || $out === false) {
            throw new \RuntimeException('Failed to open file for gunzip.');
        }
        while (!gzeof($in)) {
            $buf = gzread($in, 8192);
            if ($buf === false) {
                break;
            }
            fwrite($out, $buf);
        }
        gzclose($in);
        fclose($out);
    }

    private function buildTar(string $tarPath, string $dbDir, string $uploadsDir): void
    {
        if (is_file($tarPath)) {
            unlink($tarPath);
        }
        $tar = new \PharData($tarPath);

        // Add db.sql.gz at archive root
        foreach (glob($dbDir . '/*') ?: [] as $file) {
            $tar->addFile($file, basename($file));
        }

        // Add uploads/ tree under the "uploads/" prefix
        if (is_dir($uploadsDir)) {
            $this->addDirToTar($tar, $uploadsDir, 'uploads');
        }
    }

    private function addDirToTar(\PharData $tar, string $dir, string $prefix): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            $rel = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
            $tarPath = $prefix . '/' . str_replace('\\', '/', $rel);
            if ($file->isDir()) {
                $tar->addEmptyDir($tarPath);
            } else {
                $tar->addFile($file->getPathname(), $tarPath);
            }
        }
    }

    private function copyTree(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0775, true);
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            $rel    = ltrim(str_replace($src, '', $file->getPathname()), '/\\');
            $target = $dest . '/' . $rel;
            if ($file->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                copy($file->getPathname(), $target);
            }
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
