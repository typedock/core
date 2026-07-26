<?php
declare(strict_types=1);

// Admin login cookie. Shares the `typedock_` prefix with the PHP session
// cookie so a CDN can bypass the cache for both with a single rule. PHP only
// accepts alphanumerics and underscores here, so anything else falls back.
$authCookie = (string) env('AUTH_COOKIE_NAME', 'typedock_auth');
if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $authCookie) !== 1) {
    $authCookie = 'typedock_auth';
}

return [
    'session_lifetime' => (int) env('SESSION_LIFETIME', 86400),
    'two_factor'       => (bool) env('AUTH_TWO_FACTOR', false),
    'hash_algo'        => env('AUTH_HASH_ALGO', 'bcrypt'),
    'cookie_name'      => $authCookie,
    'brute_force'      => [
        'max_attempts' => 5,
        'lockout_time' => 900,
    ],
];
