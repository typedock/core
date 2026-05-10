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
    'sqlite' => [
        'tuning'       => env('SQLITE_TUNING', 'balanced'), // off|safe|balanced|custom
        'foreign_keys' => env('SQLITE_FOREIGN_KEYS', true),
        'busy_timeout' => (int) env('SQLITE_BUSY_TIMEOUT', 5000),
        'wal'          => env('SQLITE_WAL', true),
        'synchronous'  => env('SQLITE_SYNCHRONOUS', 'NORMAL'), // OFF|NORMAL|FULL|EXTRA
        'page_size'    => env('SQLITE_PAGE_SIZE', null),
        'cache_size'   => env('SQLITE_CACHE_SIZE', null),
        'mmap_size'    => env('SQLITE_MMAP_SIZE', null),
        'temp_store'   => env('SQLITE_TEMP_STORE', null), // DEFAULT|FILE|MEMORY
        'log_warnings' => env('SQLITE_LOG_WARNINGS', true),
    ],
];
