<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

use TypeDock\Core\Database\ConnectionFactory;
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
$pdo = ConnectionFactory::create($db, TYPEDOCK_ROOT);

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
