<?php
declare(strict_types=1);

$root = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__);
$publicDir = defined('TYPEDOCK_PUBLIC_DIR') ? TYPEDOCK_PUBLIC_DIR : $root . '/public';

return [
    // auto = container when /.dockerenv exists, source when .git exists, zip otherwise.
    // Explicit values: zip / container / source.
    'installation_mode' => (string) env('TYPEDOCK_INSTALLATION_MODE', env('INSTALLATION_MODE', 'auto')),
    'self_update_enabled' => (bool) env('SELF_UPDATE_ENABLED', true),

    'root' => $root,
    'public_dir' => $publicDir,
    'manifest_path' => $root . '/typedock-package.json',
    'tmp_dir' => $root . '/storage/tmp',
    'backup_dir' => $root . '/storage/backups',

    'channel' => (string) env('UPDATE_CHANNEL', 'stable'),
    'channels' => [
        'stable' => (string) env('UPDATE_STABLE_URL', ''),
        'rc'     => (string) env('UPDATE_RC_URL', ''),
        'beta'   => (string) env('UPDATE_BETA_URL', ''),
    ],
];
