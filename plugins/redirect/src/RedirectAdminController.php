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

    public function destroy(string $id): void
    {
        $this->ctx->db()->pdo()->prepare('DELETE FROM redirects WHERE id = ?')->execute([$id]);
        $this->ctx->redirect('', 'Redirect deleted.');
    }
}
