<?php
declare(strict_types=1);

return [
    'session_lifetime' => (int) env('SESSION_LIFETIME', 86400),
    'two_factor'       => (bool) env('AUTH_TWO_FACTOR', false),
    'hash_algo'        => env('AUTH_HASH_ALGO', 'bcrypt'),
    'cookie_name'      => 'cms_session',
    'brute_force'      => [
        'max_attempts' => 5,
        'lockout_time' => 900,
    ],
];
