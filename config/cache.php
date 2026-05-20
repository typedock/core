<?php
declare(strict_types=1);

$root = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__);

return [
    'dir'         => $root . '/storage/cache',
    'latte_dir'   => $root . '/storage/cache/latte',
    'html_dir'    => $root . '/storage/cache/html',
    'latte_auto_refresh' => filter_var(env('LATTE_AUTO_REFRESH', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
    'static_html' => (bool) env('CACHE_STATIC_HTML', false),
    'ttl'         => (int) env('CACHE_TTL', 3600),
];
