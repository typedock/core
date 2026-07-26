<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

use TypeDock\Core\PluginLoader;
use TypeDock\Core\ServiceProvider;

typedock_load_config(TYPEDOCK_ROOT);

/** ---- Argument parsing ---- */
$argv = $_SERVER['argv'] ?? [];
array_shift($argv);

$maxTime = 55;
$queue   = 'default';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--max-time=')) {
        $maxTime = max(0, (int) substr($arg, 11));
    } elseif (str_starts_with($arg, '--queue=')) {
        $queue = substr($arg, 8);
    } elseif ($arg === '--help' || $arg === '-h') {
        printUsage();
        exit(0);
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        printUsage();
        exit(2);
    }
}

// Boot the same services and plugins a web request does: image jobs run
// plugin-contributed media processors, so a worker that skipped plugins would
// quietly produce differently-optimised files than an admin upload.
(new ServiceProvider())->register();
(new PluginLoader())->load();

$runner = \Flight::job_runner();

if ($maxTime > 0) {
    // Cron mode. Returns as soon as the queue is empty, so a per-minute cron
    // entry never stacks up overlapping workers.
    $result = $runner->run($maxTime, $queue);
    printf("ran=%d failed=%d pending=%d\n", $result['ran'], $result['failed'], $result['pending']);
    exit($result['failed'] > 0 ? 1 : 0);
}

// Resident mode (VPS / container). Same tick, run back to back, with a short
// pause whenever there was nothing to do so an idle queue doesn't spin.
while (true) {
    $result = $runner->run(10, $queue);
    if ($result['ran'] === 0 && $result['failed'] === 0) {
        sleep(2);
    }
}

function printUsage(): void
{
    echo "Usage: php cli/queue-work.php [--max-time=55] [--queue=default]\n";
    echo "  --max-time=N  Work for at most N seconds, then exit (cron mode). Default 55.\n";
    echo "  --max-time=0  Stay resident and keep working until killed.\n";
}
