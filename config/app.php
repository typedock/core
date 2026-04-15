<?php
declare(strict_types=1);

return [
    'name'     => env('APP_NAME', 'TypeDock'),
    'url'      => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'locale'   => env('APP_LOCALE', 'en'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Tokyo'),
    'version'  => defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.1.0',
];
