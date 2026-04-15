<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class RedirectController extends BaseAdminController
{
    public function index(): void
    {
        $stmt      = \Flight::db()->query('SELECT * FROM redirects ORDER BY created_at DESC');
        $redirects = $stmt ? $stmt->fetchAll() : [];
        $this->render('pages/redirects/index.latte', [
            'redirects'     => $redirects,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function store(): void
    {
        $pdo = \Flight::db();
        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO redirects (id, source_path, target_url, status_code, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $id,
            trim($_POST['source_path'] ?? ''),
            trim($_POST['target_url'] ?? ''),
            (int) ($_POST['status_code'] ?? 301),
            $now,
        ]);

        $this->redirect('/admin/redirects', 'Redirect added successfully.');
    }

    public function destroy(string $id): void
    {
        \Flight::db()->prepare('DELETE FROM redirects WHERE id = ?')->execute([$id]);
        $this->redirect('/admin/redirects', 'Redirect deleted successfully.');
    }
}
