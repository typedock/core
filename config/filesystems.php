<?php
declare(strict_types=1);

$root = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__);
$publicDir = defined('TYPEDOCK_PUBLIC_DIR') ? TYPEDOCK_PUBLIC_DIR : $root . '/public';

return [
    // Default public uploads are limited to browser-safe images plus PDFs.
    // To allow additional document types, append both their MIME type and
    // filename extension here.
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
    ],
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'],
    'local'   => [
        'root' => $publicDir . '/uploads',
        'url'  => rtrim((string) env('APP_URL', 'http://localhost'), '/') . '/uploads',
    ],
];
