<?php
declare(strict_types=1);

$root = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__);

return [
    'dir'         => $root . '/storage/cache',
    'latte_dir'   => $root . '/storage/cache/latte',
    'latte_auto_refresh' => filter_var(env('LATTE_AUTO_REFRESH', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,

    // Public (CDN) cache headers. Managed from Settings -> Cache; setting
    // CACHE_PUBLIC_HEADERS=true here forces the feature on and locks the
    // admin switch, for deploys that pin configuration in files.
    // The TTL entries below are only defaults for a site that has never
    // saved the settings form.
    'public_headers'         => (bool) env('CACHE_PUBLIC_HEADERS', false),
    'edge_ttl'               => (int) env('CACHE_EDGE_TTL', 600),
    'browser_ttl'            => (int) env('CACHE_BROWSER_TTL', 0),
    'stale_while_revalidate' => (int) env('CACHE_STALE_WHILE_REVALIDATE', 86400),
];
