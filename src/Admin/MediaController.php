<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Media\MediaService;

class MediaController extends BaseAdminController
{
    private function service(): MediaService
    {
        return \Flight::media_service();
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
     * credentials — only the provider owner or public upload URL prefix.
     *
     * @return array{driver: string, label: string, details: string}
     */
    private function storageInfo(): array
    {
        $provider = \Flight::provider_registry()->claimedBy('storage');
        if ($provider !== null) {
            return [
                'driver'  => 'plugin',
                'label'   => 'Plugin storage',
                'details' => $provider,
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

    public function edit(string $id): void
    {
        $media = $this->service()->find($id);
        if ($media === null) {
            throw new \TypeDock\Exception\NotFoundException("Media not found: {$id}");
        }

        $this->render('pages/media/edit.latte', [
            'media'         => $media,
            'variants'      => $this->describeVariants($media),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $media = $this->service()->find($id);
        if ($media === null) {
            throw new \TypeDock\Exception\NotFoundException("Media not found: {$id}");
        }
        $this->authorizeOwnerOrAny($media, 'media:delete_own', 'media:manage_any', 'uploaded_by');

        $this->service()->update($id, [
            'alt_text'      => trim((string) ($_POST['alt_text'] ?? '')) ?: null,
            'caption'       => trim((string) ($_POST['caption'] ?? '')) ?: null,
            'focal_point_x' => $_POST['focal_point_x'] ?? null,
            'focal_point_y' => $_POST['focal_point_y'] ?? null,
        ]);

        $this->redirect('/admin/media/' . $id, 'Media updated.');
    }

    /**
     * Decode the thumbnails JSON blob into a render-ready list:
     * original + each thumbnail + each WebP sibling, with intended
     * max-width and public URL. Used by the Variants panel.
     *
     * @param  array<string, mixed> $media
     * @return list<array{key:string, label:string, max_width:?int, url:string, is_webp:bool}>
     */
    private function describeVariants(array $media): array
    {
        $storage  = \Flight::storage();
        $thumbs   = [];
        if (!empty($media['thumbnails'])) {
            $decoded = json_decode((string) $media['thumbnails'], true);
            if (is_array($decoded)) {
                $thumbs = $decoded;
            }
        }

        $sizeWidths = ['sm' => 300, 'md' => 768, 'lg' => 1200];
        $origWidth  = isset($media['width']) ? (int) $media['width'] : null;

        $out = [[
            'key'       => 'original',
            'label'     => 'Original',
            'max_width' => $origWidth,
            'url'       => (string) ($media['url'] ?? $storage->url((string) $media['path'])),
            'is_webp'   => ($media['mime_type'] ?? '') === 'image/webp',
        ]];

        foreach ($sizeWidths as $key => $w) {
            if (!empty($thumbs[$key])) {
                $out[] = [
                    'key'       => $key,
                    'label'     => strtoupper($key),
                    'max_width' => $w,
                    'url'       => $storage->url((string) $thumbs[$key]),
                    'is_webp'   => false,
                ];
            }
        }
        if (!empty($thumbs['original_webp'])) {
            $out[] = [
                'key'       => 'original_webp',
                'label'     => 'Original (WebP)',
                'max_width' => $origWidth,
                'url'       => $storage->url((string) $thumbs['original_webp']),
                'is_webp'   => true,
            ];
        }
        foreach ($sizeWidths as $key => $w) {
            $webpKey = $key . '_webp';
            if (!empty($thumbs[$webpKey])) {
                $out[] = [
                    'key'       => $webpKey,
                    'label'     => strtoupper($key) . ' (WebP)',
                    'max_width' => $w,
                    'url'       => $storage->url((string) $thumbs[$webpKey]),
                    'is_webp'   => true,
                ];
            }
        }

        return $out;
    }

    public function destroy(string $id): void
    {
        $media = $this->service()->find($id);
        if ($media === null) {
            throw new \TypeDock\Exception\NotFoundException("Media not found: {$id}");
        }
        $this->authorizeOwnerOrAny($media, 'media:delete_own', 'media:manage_any', 'uploaded_by');

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
