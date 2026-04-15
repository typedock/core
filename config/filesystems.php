<?php
declare(strict_types=1);

$root = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__);

return [
    'default' => env('STORAGE_DRIVER', 'local'),
    'local'   => [
        'root' => $root . '/storage/media',
        'url'  => rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/storage/media',
    ],
    's3' => [
        'key'      => env('AWS_ACCESS_KEY_ID', ''),
        'secret'   => env('AWS_SECRET_ACCESS_KEY', ''),
        'region'   => env('AWS_DEFAULT_REGION', 'ap-northeast-1'),
        'bucket'   => env('AWS_BUCKET', ''),
        'endpoint' => env('AWS_ENDPOINT', null),
        'url'      => env('AWS_URL', ''),
    ],
];
