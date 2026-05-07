<?php
declare(strict_types=1);

return [
    'name'     => env('APP_NAME', 'TypeDock'),
    'url'      => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'debug'    => (bool) env('APP_DEBUG', false),
    'locale'   => env('APP_LOCALE', 'en'),
    'timezone' => env('APP_TIMEZONE', 'Asia/Tokyo'),
    'version'  => defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.8.0',
    // External REST API is intentionally off for the MVP. Revisit this with
    // the AI integration design so scopes and allowed write paths are decided
    // together instead of exposing a half-finished API surface.
    'api_enabled' => (bool) env('API_ENABLED', false),
];
