<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', __DIR__);

require __DIR__ . '/vendor/autoload.php';

typedock_load_config(__DIR__);

$db = require __DIR__ . '/config/database.php';

$phinxConnection = match ($db['driver']) {
    'sqlite' => [
        'adapter' => 'sqlite',
        'name'    => $db['sqlite_path'],
        'suffix'  => '',
    ],
    'pgsql' => [
        'adapter' => 'pgsql',
        'host'    => $db['host'],
        'port'    => $db['port'],
        'name'    => $db['database'],
        'user'    => $db['username'],
        'pass'    => $db['password'],
        'charset' => $db['charset'],
    ],
    default => [
        'adapter'   => 'mysql',
        'host'      => $db['host'],
        'port'      => $db['port'],
        'name'      => $db['database'],
        'user'      => $db['username'],
        'pass'      => $db['password'],
        'charset'   => $db['charset'],
        'collation' => 'utf8mb4_unicode_ci',
    ],
};

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/migrations',
        'seeds'      => '%%PHINX_CONFIG_DIR%%/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => 'default',
        'default'                 => $phinxConnection,
    ],
    'version_order' => 'creation',
];
