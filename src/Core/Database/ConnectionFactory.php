<?php
declare(strict_types=1);

namespace TypeDock\Core\Database;

use flight\database\SimplePdo;
use PDO;
use RuntimeException;

final class ConnectionFactory
{
    /**
     * @param array<string,mixed> $db
     */
    public static function create(array $db, ?string $root = null): PDO
    {
        $root ??= defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__, 3);
        $driver = strtolower((string) ($db['driver'] ?? 'mysql'));

        if ($driver === 'libsql') {
            return self::createLibsql($db);
        }

        $dsn = match ($driver) {
            'sqlite' => 'sqlite:' . ($db['sqlite_path'] ?? $root . '/storage/database.sqlite'),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $db['host'] ?? '127.0.0.1',
                (int) ($db['port'] ?? 5432),
                $db['database'] ?? 'typedock',
            ),
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'] ?? '127.0.0.1',
                (int) ($db['port'] ?? 3306),
                $db['database'] ?? 'typedock',
                $db['charset'] ?? 'utf8mb4',
            ),
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };

        $pdo = new SimplePdo(
            $dsn,
            $driver === 'sqlite' ? null : (string) ($db['username'] ?? 'root'),
            $driver === 'sqlite' ? null : (string) ($db['password'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        if ($driver === 'sqlite') {
            SqlitePragmas::apply($pdo, $db);
        }

        return $pdo;
    }

    public static function schemaDriver(string $driver): string
    {
        return strtolower($driver) === 'libsql' ? 'sqlite' : strtolower($driver);
    }

    /**
     * @param array<string,mixed> $db
     */
    private static function createLibsql(array $db): PDO
    {
        return new LibsqlPdo(
            trim((string) ($db['libsql_url'] ?? '')),
            trim((string) ($db['libsql_auth_token'] ?? '')),
            [
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                'timeout' => (int) ($db['libsql_http_timeout'] ?? 15),
                'connect_timeout' => (int) ($db['libsql_connect_timeout'] ?? 5),
                'allow_insecure' => (bool) ($db['libsql_allow_insecure'] ?? false),
            ],
        );
    }
}
