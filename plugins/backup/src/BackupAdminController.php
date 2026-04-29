<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Backup;

use TypeDock\Core\PluginContext;

final class BackupAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    public function index(): void
    {
        $service = $this->service();
        $this->ctx->view('templates/admin/index.latte', [
            'backups'       => $service->listBackups(),
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $note = trim((string) ($_POST['note'] ?? ''));
        try {
            $result = $this->service()->create($note);
            $this->ctx->log()->info('Backup created', [
                'filename' => $result['filename'],
                'size'     => $result['size'],
            ]);
            $this->ctx->redirect('', "Backup created: {$result['filename']} ({$this->humanSize($result['size'])}).");
        } catch (\Throwable $e) {
            $this->ctx->log()->error('Backup failed: ' . $e->getMessage());
            $this->ctx->redirect('', 'Backup failed: ' . $e->getMessage(), 'error');
        }
    }

    public function download(string $id): void
    {
        $service = $this->service();
        $row = $service->findById($id);
        if ($row === null) {
            http_response_code(404);
            echo 'Backup not found.';
            return;
        }

        // Resolve the file path through the configured backup dir and reject
        // anything that escapes it. The DB only stores the basename, but
        // belt-and-braces keeps a corrupted row from reading arbitrary files.
        $path = realpath($service->backupDir() . '/' . basename((string) $row['filename']));
        $root = realpath($service->backupDir());
        if ($path === false || $root === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            http_response_code(404);
            echo 'Backup file is missing on disk.';
            return;
        }

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }

    public function restore(string $id): void
    {
        $service = $this->service();
        $row = $service->findById($id);
        if ($row === null) {
            $this->ctx->redirect('', 'Backup not found.', 'error');
            return;
        }
        $path = $service->backupDir() . '/' . basename((string) $row['filename']);
        try {
            $service->restore($path);
            $this->ctx->redirect('', 'Backup restored.');
        } catch (\Throwable $e) {
            $this->ctx->redirect('', 'Restore failed: ' . $e->getMessage(), 'error');
        }
    }

    public function destroy(string $id): void
    {
        $this->service()->deleteById($id);
        $this->ctx->redirect('', 'Backup deleted.');
    }

    private function service(): BackupService
    {
        $root       = defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__, 4);
        $backupDir  = (string) config('backup.dir', $root . '/storage/backups');
        $uploadsDir = (string) config('backup.uploads_dir', $root . '/storage/uploads');
        return new BackupService($this->ctx->db()->pdo(), $backupDir, $uploadsDir);
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
