<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Redirect;

use TypeDock\Core\PluginContext;

final class RedirectAdminController
{
    public function __construct(private readonly PluginContext $ctx) {}

    public function index(): void
    {
        $stmt = $this->ctx->db()->pdo()->query(
            'SELECT * FROM redirects ORDER BY created_at DESC'
        );
        $redirects = $stmt ? $stmt->fetchAll() : [];

        $this->ctx->view('templates/admin/index.latte', [
            'redirects'     => $redirects,
            'flash_success' => $this->ctx->getFlash('success'),
            'flash_error'   => $this->ctx->getFlash('error'),
        ]);
    }

    public function store(): void
    {
        $source = trim((string) ($_POST['source_path'] ?? ''));
        $target = trim((string) ($_POST['target_url'] ?? ''));
        $status = (int) ($_POST['status_code'] ?? 301);
        if (!in_array($status, [301, 302, 307, 308], true)) {
            $status = 301;
        }

        if ($source === '' || $target === '') {
            $this->ctx->redirect('', 'Source and target are required.', 'error');
            return;
        }

        $this->ctx->db()->pdo()->prepare(
            'INSERT INTO redirects (id, source_path, target_url, status_code, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            typedock_uuid7(),
            $source,
            $target,
            $status,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->ctx->log()->info('Redirect added', ['source' => $source, 'target' => $target]);
        $this->ctx->redirect('', 'Redirect added.');
    }

    /**
     * Bulk-load rules from a CSV or JSON file — the realistic way to move a
     * site's worth of old URLs, and a direct home for the redirect map the
     * content importer hands out after a migration.
     */
    public function import(): void
    {
        $file = $_FILES['redirect_file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->ctx->redirect('', 'Upload failed. The file may be larger than this server allows.', 'error');
            return;
        }

        $name = strtolower(basename((string) $file['name']));
        if (preg_match('/\.(csv|json)$/', $name) !== 1) {
            $this->ctx->redirect('', 'Only .csv and .json files are accepted.', 'error');
            return;
        }

        // The file is read where PHP put it rather than moved, so this is the
        // guard that keeps a crafted tmp_name from reading anything else.
        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            $this->ctx->redirect('', 'Upload failed.', 'error');
            return;
        }

        try {
            $result = (new RedirectImport($this->ctx->db()->pdo()))->importFile($tmp, $name);
        } catch (\Throwable $e) {
            $this->ctx->log()->error('Redirect import failed', ['error' => $e->getMessage()]);
            $this->ctx->redirect('', $e->getMessage(), 'error');
            return;
        }

        $this->ctx->log()->info('Redirects imported', $result);

        $this->ctx->flash('success', sprintf(
            'Imported %d redirect(s): %d added, %d updated, %d skipped.',
            $result['created'] + $result['updated'],
            $result['created'],
            $result['updated'],
            $result['skipped']
        ));

        if ($result['errors'] !== []) {
            $this->ctx->flash('error', 'Skipped rows — ' . implode(' ', $result['errors']));
        }

        $this->ctx->redirect('');
    }

    public function destroy(string $id): void
    {
        $this->ctx->db()->pdo()->prepare('DELETE FROM redirects WHERE id = ?')->execute([$id]);
        $this->ctx->redirect('', 'Redirect deleted.');
    }
}
