<?php
declare(strict_types=1);

return [
    'driver'      => env('DB_DRIVER', 'mysql'),
    'host'        => env('DB_HOST', '127.0.0.1'),
    'port'        => (int) env('DB_PORT', 3306),
    'database'    => env('DB_DATABASE', 'typedock'),
    'username'    => env('DB_USERNAME', 'root'),
    'password'    => env('DB_PASSWORD', ''),
    'charset'     => env('DB_CHARSET', 'utf8mb4'),
    'prefix'      => env('DB_PREFIX', ''),
    'sqlite_path' => env('DB_SQLITE_PATH', defined('TYPEDOCK_ROOT')
        ? TYPEDOCK_ROOT . '/storage/database.sqlite'
        : __DIR__ . '/../storage/database.sqlite'
    ),
];
