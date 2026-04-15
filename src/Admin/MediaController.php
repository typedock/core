<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Media\MediaService;

class MediaController extends BaseAdminController
{
    private function service(): MediaService
    {
        return new MediaService(\Flight::db(), \Flight::storage());
    }

    public function index(): void
    {
        $result = $this->service()->list(['per_page' => 40, 'page' => max(1, (int) ($_GET['page'] ?? 1))]);
        $this->render('pages/media/index.latte', [
            'media'         => $result['items'],
            'total'         => $result['total'],
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function upload(): void
    {
        $user = \Flight::get('current_user');

        if (empty($_FILES['file'])) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'No file uploaded']);
            return;
        }

        try {
            $media = $this->service()->upload($_FILES['file'], '/', $user['id'] ?? null);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'media' => $media]);
        } catch (\TypeDock\Exception\ValidationException $e) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'errors' => $e->getErrors()]);
        }
    }

    public function destroy(string $id): void
    {
        $this->service()->delete($id);
        $this->redirect('/admin/media', 'Media deleted successfully.');
    }
}
