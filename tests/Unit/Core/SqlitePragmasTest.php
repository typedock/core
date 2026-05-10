<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use PDO;
use PHPUnit\Framework\TestCase;
use TypeDock\Core\Database\SqlitePragmas;

final class SqlitePragmasTest extends TestCase
{
    public function testBalancedProfileAppliesSafeConnectionSettings(): void
    {
        $path = sys_get_temp_dir() . '/typedock-sqlite-pragmas-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        try {
            $warnings = SqlitePragmas::apply($pdo, [
                'driver' => 'sqlite',
                'sqlite_path' => $path,
                'sqlite' => [
                    'tuning' => 'balanced',
                    'log_warnings' => false,
                ],
            ]);

            self::assertSame([], $warnings);
            self::assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
            self::assertSame(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
            self::assertSame(5000, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
            self::assertSame(1, (int) $pdo->query('PRAGMA synchronous')->fetchColumn());
        } finally {
            unset($pdo);
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function testCustomProfileAppliesAllowlistedAdvancedSettings(): void
    {
        $pdo = $this->pdo();

        $warnings = SqlitePragmas::apply($pdo, [
            'driver' => 'sqlite',
            'sqlite_path' => ':memory:',
            'sqlite' => [
                'tuning' => 'custom',
                'busy_timeout' => '1234',
                'wal' => false,
                'synchronous' => 'FULL',
                'cache_size' => '-2048',
                'mmap_size' => '0',
                'temp_store' => 'MEMORY',
                'log_warnings' => false,
            ],
        ]);

        self::assertSame([], $warnings);
        self::assertSame(1234, (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('PRAGMA synchronous')->fetchColumn());
        self::assertSame(-2048, (int) $pdo->query('PRAGMA cache_size')->fetchColumn());
        self::assertSame(2, (int) $pdo->query('PRAGMA temp_store')->fetchColumn());
    }

    public function testInvalidCustomValuesAreIgnoredWithWarnings(): void
    {
        $pdo = $this->pdo();

        $warnings = SqlitePragmas::apply($pdo, [
            'driver' => 'sqlite',
            'sqlite_path' => ':memory:',
            'sqlite' => [
                'tuning' => 'custom',
                'wal' => false,
                'synchronous' => 'NORMAL; DROP TABLE users',
                'cache_size' => 'lots',
                'temp_store' => 'RAM',
                'log_warnings' => false,
            ],
        ]);

        self::assertContains('Invalid synchronous setting ignored.', $warnings);
        self::assertContains('Invalid cache_size ignored.', $warnings);
        self::assertContains('Invalid temp_store ignored.', $warnings);
    }

    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
