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
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 40;
        $result  = $this->service()->list(['per_page' => $perPage, 'page' => $page]);

        $this->render('pages/media/index.latte', [
            'media'         => $result['items'],
            'total'         => $result['total'],
            'page'          => $page,
            'per_page'      => $perPage,
            'total_pages'   => max(1, (int) ceil($result['total'] / $perPage)),
            'storage'       => $this->storageInfo(),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    /**
     * Summarise the active storage driver for the UI badge. We don't expose
     * credentials — only the driver name and a label the admin can recognise
     * (bucket name for S3, public upload URL prefix for local).
     *
     * @return array{driver: string, label: string, details: string}
     */
    private function storageInfo(): array
    {
        $driver = (string) config('filesystems.default', 'local');

        if ($driver === 's3') {
            $s3 = config('filesystems.s3', []);
            return [
                'driver'  => 's3',
                'label'   => 'Amazon S3',
                'details' => (string) ($s3['bucket'] ?? '(no bucket configured)'),
            ];
        }

        $local = config('filesystems.local', []);
        return [
            'driver'  => 'local',
            'label'   => 'Local filesystem',
            'details' => (string) ($local['url'] ?? '/uploads'),
        ];
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

    /**
     * JSON browse endpoint used by the Media Library grid (incremental
     * refresh after upload) and the shared media picker modal mounted in
     * the block editor / SEO panel.
     *
     * Only `image/` types are exposed by default; pass `?type=all` to
     * include video/audio/documents.
     */
    public function browse(): void
    {
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = min(max((int) ($_GET['per_page'] ?? 40), 1), 100);
        $search  = trim((string) ($_GET['q'] ?? ''));
        $type    = (string) ($_GET['type'] ?? 'image');

        $options = ['page' => $page, 'per_page' => $perPage];
        if ($search !== '') {
            $options['search'] = $search;
        }
        if ($type === 'image') {
            $options['mime_type'] = 'image/';
        }

        $result  = $this->service()->list($options);
        $storage = \Flight::storage();

        $items = array_map(function (array $row) use ($storage): array {
            $thumbs = [];
            if (!empty($row['thumbnails'])) {
                $decoded = json_decode((string) $row['thumbnails'], true);
                if (is_array($decoded)) {
                    foreach ($decoded as $size => $path) {
                        $thumbs[$size] = $storage->url((string) $path);
                    }
                }
            }
            $row['thumbnail_url'] = $thumbs['sm'] ?? $thumbs['md'] ?? $row['url'] ?? null;
            $row['thumbnails']    = $thumbs;
            return $row;
        }, $result['items']);

        header('Content-Type: application/json');
        echo json_encode([
            'ok'       => true,
            'items'    => $items,
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }
}
