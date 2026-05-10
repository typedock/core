<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

use TypeDock\Core\Database\SqlitePragmas;
use TypeDock\Core\Migration\Migrator;

typedock_load_config(TYPEDOCK_ROOT);

/** Parse argv */
$argv = $_SERVER['argv'] ?? [];
array_shift($argv); // script name

$command = 'migrate';

foreach ($argv as $arg) {
    if (in_array($arg, ['migrate', 'status'], true)) {
        $command = $arg;
    } elseif (str_starts_with($arg, '--env=') || $arg === '--env=development' || $arg === '--env=default') {
        // Accepted for backwards compatibility; the app uses a single environment from config/database.php.
        continue;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: migrate.php [migrate|status]\n");
        exit(2);
    }
}

$db = require TYPEDOCK_ROOT . '/config/database.php';
$pdo = makePdoForCli($db, TYPEDOCK_ROOT);

$migrator = new Migrator($pdo, $db['driver'], TYPEDOCK_ROOT . '/migrations');

echo "TypeDock Migrate CLI\n";
echo "Driver:  {$db['driver']}\n";
echo "Command: {$command}\n";
echo str_repeat('-', 60) . "\n";

try {
    if ($command === 'status') {
        foreach ($migrator->status() as $row) {
            $flag = $row['applied_at'] ? 'up  ' : 'down';
            printf("  %s  %-20s  %s  %s\n", $flag, $row['version'], $row['name'], (string) $row['applied_at']);
        }
        exit(0);
    }

    $result = $migrator->migrate();
    foreach ($result['applied'] as $row) {
        echo "  applied {$row['version']}  {$row['name']}\n";
    }
    if ($result['errors'] !== []) {
        $first = $result['errors'][0];
        fwrite(STDERR, "\nError applying {$first['version']} ({$first['name']}): {$first['message']}\n");
        exit(1);
    }
    echo "Migration complete. " . count($result['applied']) . " migration(s) applied.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "\nError: " . $e->getMessage() . "\n");
    exit(1);
}

/**
 * @param array<string,mixed> $db
 */
function makePdoForCli(array $db, string $root): PDO
{
    $driver  = (string) ($db['driver'] ?? 'mysql');
    $charset = (string) ($db['charset'] ?? 'utf8mb4');

    $dsn = match ($driver) {
        'sqlite' => 'sqlite:' . ($db['sqlite_path'] ?? $root . '/storage/database.sqlite'),
        'pgsql'  => sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'] ?? '127.0.0.1', (int) ($db['port'] ?? 5432), $db['database'] ?? ''),
        default  => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'] ?? '127.0.0.1', (int) ($db['port'] ?? 3306), $db['database'] ?? '', $charset),
    };

    $pdo = new PDO(
        $dsn,
        $driver === 'sqlite' ? null : (string) ($db['username'] ?? ''),
        $driver === 'sqlite' ? null : (string) ($db['password'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if ($driver === 'sqlite') {
        SqlitePragmas::apply($pdo, $db);
    }

    return $pdo;
}
