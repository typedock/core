<?php
declare(strict_types=1);

$root = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__);
$publicDir = defined('TYPEDOCK_PUBLIC_DIR') ? TYPEDOCK_PUBLIC_DIR : $root . '/public';
$primaryKeyOverride = trim((string) env('UPDATE_MINISIGN_PUBLIC_KEY', ''));
$recoveryKeyOverride = trim((string) env('UPDATE_RECOVERY_MINISIGN_PUBLIC_KEY', ''));

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

    'channel' => (string) env(
        'UPDATE_CHANNEL',
        str_contains(defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '', '-') ? 'rc' : 'stable',
    ),
    'channels' => [
        'stable' => (string) env('UPDATE_STABLE_URL', 'https://raw.githubusercontent.com/typedock/core/release-channel/stable.json'),
        'rc'     => (string) env('UPDATE_RC_URL', 'https://raw.githubusercontent.com/typedock/core/release-channel/rc.json'),
        'beta'   => (string) env('UPDATE_BETA_URL', 'https://raw.githubusercontent.com/typedock/core/release-channel/beta.json'),
    ],
    'minisign_public_key' => $primaryKeyOverride !== ''
        ? $primaryKeyOverride
        : \TypeDock\Update\Trust::PRIMARY_MINISIGN_PUBLIC_KEY,
    'recovery_minisign_public_key' => $recoveryKeyOverride !== ''
        ? $recoveryKeyOverride
        : \TypeDock\Update\Trust::RECOVERY_MINISIGN_PUBLIC_KEY,
    'metadata_cache_path' => $root . '/storage/tmp/update-release.json',
    'state_path' => $root . '/storage/upgrade-state.json',
];
