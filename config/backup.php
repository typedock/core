<?php
declare(strict_types=1);

$root = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__);

return [
    'dir'         => env('BACKUP_DIR', $root . '/storage/backups'),
    'uploads_dir' => env('BACKUP_UPLOADS_DIR', $root . '/storage/uploads'),
];
