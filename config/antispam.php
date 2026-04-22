<?php
declare(strict_types=1);

return [
    'honeypot_field' => env('ANTISPAM_HONEYPOT_FIELD', 'website'),
    'rate_limit'     => (int) env('ANTISPAM_RATE_LIMIT', 5),
    'window_seconds' => (int) env('ANTISPAM_WINDOW_SECONDS', 60),
];
