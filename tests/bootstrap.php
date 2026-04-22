<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for TypeDock.
 *
 * - Loads Composer's autoloader (which also pulls in src/helpers.php).
 * - Defines TYPEDOCK_ROOT pointing at the project root.
 * - Sets sensible env defaults so config() helpers can resolve a SQLite DB
 *   for integration tests without touching the developer's real config.php.
 */

if (!defined('TYPEDOCK_ROOT')) {
    define('TYPEDOCK_ROOT', dirname(__DIR__));
}

require TYPEDOCK_ROOT . '/vendor/autoload.php';

// Per-test-run SQLite database file. SQLite's :memory: handle cannot be shared
// across connections, so we point at a unique tempfile that is cleaned up on
// shutdown.
$sqlitePath = sys_get_temp_dir() . '/typedock-test-' . bin2hex(random_bytes(6)) . '.sqlite';

$envDefaults = [
    'APP_ENV'        => 'testing',
    'APP_DEBUG'      => 'true',
    'DB_DRIVER'      => 'sqlite',
    'DB_SQLITE_PATH' => $sqlitePath,
];

foreach ($envDefaults as $k => $v) {
    if (getenv($k) === false || getenv($k) === '') {
        $_ENV[$k] = $v;
        putenv("{$k}={$v}");
    }
}

// Best-effort cleanup of the per-run SQLite file.
register_shutdown_function(static function () use ($sqlitePath): void {
    if (is_file($sqlitePath)) {
        @unlink($sqlitePath);
    }
});
