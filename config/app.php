<?php
declare(strict_types=1);

return [
    'name'     => env('APP_NAME', 'TypeDock'),
    'url'      => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'locale'   => env('APP_LOCALE', 'en'),
    'admin_locale' => env('ADMIN_LOCALE', 'en'),
    'admin_locale_cookie' => env('ADMIN_LOCALE_COOKIE', 'typedock_admin_locale'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Tokyo'),
    'version'  => defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '1.0.0-rc5',
    // External REST API default. Admins can also enable it from Settings -> API;
    // this env flag is a deployment-level override that locks the API on.
    'api_enabled' => (bool) env('API_ENABLED', false),
];
