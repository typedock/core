<?php
declare(strict_types=1);

return [
    'default'    => env('MAIL_MAILER', 'php'),
    'from_email' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
    'from_name'  => env('MAIL_FROM_NAME', env('APP_NAME', 'TypeDock')),
    'smtp' => [
        'host'       => env('MAIL_HOST', 'smtp.mailtrap.io'),
        'port'       => (int) env('MAIL_PORT', 587),
        'username'   => env('MAIL_USERNAME', ''),
        'password'   => env('MAIL_PASSWORD', ''),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
    ],
];
