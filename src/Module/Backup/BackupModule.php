<?php
declare(strict_types=1);

namespace TypeDock\Module\Backup;

use TypeDock\Contract\ModuleInterface;

class BackupModule implements ModuleInterface
{
    public function register(): void
    {
        \Flight::map('backup', function (): BackupService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $root       = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__, 3);
            $backupDir  = (string) config('backup.dir', $root . '/storage/backups');
            $uploadsDir = (string) config('backup.uploads_dir', $root . '/storage/uploads');
            $service    = new BackupService(\Flight::db(), $backupDir, $uploadsDir);
            return $service;
        });
    }
}
