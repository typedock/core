<?php
declare(strict_types=1);

namespace TypeDock\Core\Database;

use PDO;
use Throwable;

final class SqlitePragmas
{
    /**
     * @param array<string,mixed> $db
     * @return array<int,string> Best-effort tuning warnings.
     */
    public static function apply(PDO $pdo, array $db): array
    {
        $options = self::options($db);
        $warnings = [];

        if (self::bool($options['foreign_keys'] ?? true, true)) {
            self::tryExec($pdo, 'PRAGMA foreign_keys = ON', $warnings, 'foreign_keys could not be enabled');
        }

        $busyTimeout = self::intInRange($options['busy_timeout'] ?? 5000, 0, 600000);
        if ($busyTimeout !== null) {
            self::tryExec($pdo, 'PRAGMA busy_timeout = ' . $busyTimeout, $warnings, 'busy_timeout could not be set');
        }

        $profile = strtolower((string) ($options['tuning'] ?? 'balanced'));
        if (!in_array($profile, ['off', 'safe', 'balanced', 'custom'], true)) {
            $warnings[] = "Unknown SQLite tuning profile '{$profile}', using balanced.";
            $profile = 'balanced';
        }

        if ($profile === 'custom') {
            self::applyCustomBeforeWal($pdo, $options, $warnings);
        }

        if ($profile === 'balanced' || $profile === 'custom') {
            self::applyWalAndSync($pdo, $db, $options, $profile, $warnings);
        }

        if ($profile === 'custom') {
            self::applyCustomAfterWal($pdo, $options, $warnings);
        }

        if ($warnings !== [] && self::bool($options['log_warnings'] ?? true, true)) {
            foreach ($warnings as $warning) {
                error_log('[TypeDock SQLite] ' . $warning);
            }
        }

        return $warnings;
    }

    /**
     * @param array<string,mixed> $db
     * @return array<string,mixed>
     */
    private static function options(array $db): array
    {
        $sqlite = $db['sqlite'] ?? [];
        return is_array($sqlite) ? $sqlite : [];
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,string> $warnings
     */
    private static function applyCustomBeforeWal(PDO $pdo, array $options, array &$warnings): void
    {
        if (array_key_exists('page_size', $options) && $options['page_size'] !== null && $options['page_size'] !== '') {
            $pageSize = self::intInSet($options['page_size'], [512, 1024, 2048, 4096, 8192, 16384, 32768, 65536]);
            if ($pageSize === null) {
                $warnings[] = 'Invalid page_size ignored.';
            } else {
                self::tryExec($pdo, 'PRAGMA page_size = ' . $pageSize, $warnings, 'page_size could not be set');
            }
        }
    }

    /**
     * @param array<string,mixed> $db
     * @param array<string,mixed> $options
     * @param array<int,string> $warnings
     */
    private static function applyWalAndSync(PDO $pdo, array $db, array $options, string $profile, array &$warnings): void
    {
        $sqlitePath = (string) ($db['sqlite_path'] ?? '');
        $isMemory = $sqlitePath === ':memory:' || $sqlitePath === '';
        $walEnabled = false;

        if (!$isMemory && self::bool($options['wal'] ?? true, true)) {
            $mode = self::tryQueryValue($pdo, 'PRAGMA journal_mode = WAL', $warnings, 'WAL journal_mode could not be requested');
            if ($mode !== null && strtolower((string) $mode) !== 'wal') {
                $warnings[] = 'SQLite returned journal_mode=' . (string) $mode . ' instead of wal.';
            } elseif ($mode !== null) {
                $walEnabled = true;
            }
        }

        if ($profile === 'balanced' && !$walEnabled) {
            return;
        }

        $synchronous = self::synchronous($options['synchronous'] ?? 'NORMAL');
        if ($synchronous === null) {
            $warnings[] = 'Invalid synchronous setting ignored.';
            return;
        }

        self::tryExec($pdo, 'PRAGMA synchronous = ' . $synchronous, $warnings, 'synchronous could not be set');
    }

    /**
     * @param array<string,mixed> $options
     * @param array<int,string> $warnings
     */
    private static function applyCustomAfterWal(PDO $pdo, array $options, array &$warnings): void
    {
        if (array_key_exists('cache_size', $options) && $options['cache_size'] !== null && $options['cache_size'] !== '') {
            $cacheSize = self::intInRange($options['cache_size'], -1048576, 1048576);
            if ($cacheSize === null || $cacheSize === 0) {
                $warnings[] = 'Invalid cache_size ignored.';
            } else {
                self::tryExec($pdo, 'PRAGMA cache_size = ' . $cacheSize, $warnings, 'cache_size could not be set');
            }
        }

        if (array_key_exists('mmap_size', $options) && $options['mmap_size'] !== null && $options['mmap_size'] !== '') {
            $mmapSize = self::intInRange($options['mmap_size'], 0, 2147483647);
            if ($mmapSize === null) {
                $warnings[] = 'Invalid mmap_size ignored.';
            } else {
                self::tryExec($pdo, 'PRAGMA mmap_size = ' . $mmapSize, $warnings, 'mmap_size could not be set');
            }
        }

        if (array_key_exists('temp_store', $options) && $options['temp_store'] !== null && $options['temp_store'] !== '') {
            $tempStore = self::tempStore($options['temp_store']);
            if ($tempStore === null) {
                $warnings[] = 'Invalid temp_store ignored.';
            } else {
                self::tryExec($pdo, 'PRAGMA temp_store = ' . $tempStore, $warnings, 'temp_store could not be set');
            }
        }
    }

    /**
     * @param array<int,string> $warnings
     */
    private static function tryExec(PDO $pdo, string $sql, array &$warnings, string $message): void
    {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            $warnings[] = $message . ': ' . $e->getMessage();
        }
    }

    /**
     * @param array<int,string> $warnings
     */
    private static function tryQueryValue(PDO $pdo, string $sql, array &$warnings, string $message): mixed
    {
        try {
            $stmt = $pdo->query($sql);
            return $stmt ? $stmt->fetchColumn() : null;
        } catch (Throwable $e) {
            $warnings[] = $message . ': ' . $e->getMessage();
            return null;
        }
    }

    private static function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return $default;
        }
        $normalized = strtolower((string) $value);
        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    private static function intInRange(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            $int = (int) $value;
        } else {
            return null;
        }

        return $int >= $min && $int <= $max ? $int : null;
    }

    /**
     * @param array<int,int> $allowed
     */
    private static function intInSet(mixed $value, array $allowed): ?int
    {
        $int = self::intInRange($value, min($allowed), max($allowed));
        return $int !== null && in_array($int, $allowed, true) ? $int : null;
    }

    private static function synchronous(mixed $value): ?string
    {
        $normalized = strtoupper((string) $value);
        return in_array($normalized, ['OFF', 'NORMAL', 'FULL', 'EXTRA'], true) ? $normalized : null;
    }

    private static function tempStore(mixed $value): ?string
    {
        $normalized = strtoupper((string) $value);
        return match ($normalized) {
            '0', 'DEFAULT' => 'DEFAULT',
            '1', 'FILE' => 'FILE',
            '2', 'MEMORY' => 'MEMORY',
            default => null,
        };
    }
}
