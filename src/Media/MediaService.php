<?php
declare(strict_types=1);

namespace TypeDock\Media;

use TypeDock\Contract\StorageDriver;

class MediaService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        'video/mp4', 'video/webm',
        'audio/mpeg', 'audio/ogg',
        'application/pdf',
        'application/zip',
        'text/plain', 'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const THUMBNAIL_SIZES = ['sm' => 300, 'md' => 768, 'lg' => 1200];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly StorageDriver $storage
    ) {}

    /**
     * Upload a file from $_FILES array or a local path.
     *
     * @param  array{name: string, tmp_name: string, size: int, type: string, error: int} $file
     * @return array<string, mixed>
     */
    public function upload(array $file, string $folder = '/', ?string $uploadedBy = null): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \TypeDock\Exception\ValidationException(
                ['file' => ['File upload failed.']]
            );
        }

        $mimeType = $this->detectMimeType($file['tmp_name']);
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \TypeDock\Exception\ValidationException(
                ['file' => ['This file format is not supported.']]
            );
        }

        $originalName = (string) $file['name'];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $safeName     = \Ramsey\Uuid\Uuid::uuid7()->toString() . '.' . $ext;
        $folder       = '/' . trim($folder, '/');
        $year         = date('Y');
        $month        = date('m');
        $storagePath  = ltrim($folder . '/' . $year . '/' . $month . '/' . $safeName, '/');

        $this->storage->putFile($storagePath, $file['tmp_name']);

        // Get image dimensions
        $width  = null;
        $height = null;
        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            [$width, $height] = $this->getImageDimensions($file['tmp_name']);
        }

        // Generate thumbnails
        $thumbnails = null;
        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true) && $mimeType !== 'image/svg+xml') {
            $thumbnails = $this->generateThumbnails($file['tmp_name'], $storagePath, $ext);
        }

        // Save to DB
        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO media (id, path, original_filename, mime_type, file_size, width, height,
                                alt_text, caption, focal_point_x, focal_point_y, folder, thumbnails,
                                uploaded_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $storagePath,
            $originalName,
            $mimeType,
            (int) $file['size'],
            $width,
            $height,
            null, // alt_text
            null, // caption
            null, // focal_point_x
            null, // focal_point_y
            $folder . '/' . $year . '/' . $month,
            $thumbnails !== null ? json_encode($thumbnails) : null,
            $uploadedBy,
            $now,
        ]);

        return $this->find($id);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['url'] = $this->storage->url((string) $row['path']);
        return $row;
    }

    /**
     * @param  array<string, mixed> $options
     * @return array{items: array<array<string, mixed>>, total: int}
     */
    public function list(array $options = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (isset($options['folder'])) {
            $where[]  = 'folder LIKE ?';
            $params[] = $options['folder'] . '%';
        }
        if (isset($options['mime_type'])) {
            $where[]  = 'mime_type LIKE ?';
            $params[] = $options['mime_type'] . '%';
        }
        if (isset($options['search'])) {
            $where[]  = '(original_filename LIKE ? OR alt_text LIKE ?)';
            $params[] = '%' . $options['search'] . '%';
            $params[] = '%' . $options['search'] . '%';
        }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM media WHERE {$whereStr}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = min((int) ($options['per_page'] ?? 40), 200);
        $page    = max(1, (int) ($options['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT * FROM media WHERE {$whereStr} ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $items = $stmt->fetchAll();

        foreach ($items as &$item) {
            $item['url'] = $this->storage->url((string) $item['path']);
        }
        unset($item);

        return compact('items', 'total');
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $this->pdo->prepare(
            'UPDATE media SET alt_text = ?, caption = ?, focal_point_x = ?, focal_point_y = ? WHERE id = ?'
        )->execute([
            $data['alt_text'] ?? null,
            $data['caption'] ?? null,
            isset($data['focal_point_x']) ? (float) $data['focal_point_x'] : null,
            isset($data['focal_point_y']) ? (float) $data['focal_point_y'] : null,
            $id,
        ]);
        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $media = $this->find($id);
        if ($media === null) {
            return;
        }

        // Delete main file
        $this->storage->delete((string) $media['path']);

        // Delete thumbnails
        if (!empty($media['thumbnails'])) {
            $thumbnails = json_decode((string) $media['thumbnails'], true);
            if (is_array($thumbnails)) {
                foreach ($thumbnails as $path) {
                    $this->storage->delete((string) $path);
                }
            }
        }

        $this->pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);
    }

    private function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($filePath) ?: 'application/octet-stream';
    }

    /** @return array{int|null, int|null} */
    private function getImageDimensions(string $filePath): array
    {
        $size = getimagesize($filePath);
        if ($size === false) {
            return [null, null];
        }
        return [$size[0], $size[1]];
    }

    /**
     * Generate thumbnails using Intervention Image or GD fallback.
     *
     * @return array<string, string>|null
     */
    private function generateThumbnails(string $tmpPath, string $storagePath, string $ext): ?array
    {
        $dir        = dirname($storagePath);
        $baseName   = pathinfo($storagePath, PATHINFO_FILENAME);
        $thumbnails = [];

        // Try Intervention Image first
        if (class_exists(\Intervention\Image\ImageManager::class)) {
            try {
                $driver  = extension_loaded('imagick') ? 'imagick' : 'gd';
                $manager = new \Intervention\Image\ImageManager(['driver' => $driver]);

                foreach (self::THUMBNAIL_SIZES as $sizeName => $maxWidth) {
                    $image = $manager->make($tmpPath);
                    if ($image->width() > $maxWidth) {
                        $image->resize($maxWidth, null, function ($constraint): void {
                            $constraint->aspectRatio();
                            $constraint->upsize();
                        });
                    }
                    $thumbPath = $dir . '/' . $baseName . '-' . $sizeName . '.' . $ext;
                    $tempFile  = sys_get_temp_dir() . '/' . uniqid('thumb_') . '.' . $ext;
                    $image->save($tempFile, 85);
                    $this->storage->putFile($thumbPath, $tempFile);
                    @unlink($tempFile);
                    $thumbnails[$sizeName] = $thumbPath;
                }
                return $thumbnails;
            } catch (\Throwable) {
                // Fall through to GD
            }
        }

        // GD fallback
        if (!extension_loaded('gd')) {
            return null;
        }

        try {
            $sourceImage = match (true) {
                in_array($ext, ['jpg', 'jpeg']) => imagecreatefromjpeg($tmpPath),
                $ext === 'png'                  => imagecreatefrompng($tmpPath),
                $ext === 'gif'                  => imagecreatefromgif($tmpPath),
                $ext === 'webp'                 => imagecreatefromwebp($tmpPath),
                default                         => false,
            };

            if ($sourceImage === false) {
                return null;
            }

            $srcW = imagesx($sourceImage);
            $srcH = imagesy($sourceImage);

            foreach (self::THUMBNAIL_SIZES as $sizeName => $maxWidth) {
                if ($srcW <= $maxWidth) {
                    continue;
                }
                $ratio = $maxWidth / $srcW;
                $newW  = $maxWidth;
                $newH  = (int) ($srcH * $ratio);
                $thumb = imagecreatetruecolor($newW, $newH);

                if ($thumb === false) {
                    continue;
                }

                // Preserve transparency for PNG
                if ($ext === 'png') {
                    imagealphablending($thumb, false);
                    imagesavealpha($thumb, true);
                }

                imagecopyresampled($thumb, $sourceImage, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

                $tempFile  = sys_get_temp_dir() . '/' . uniqid('thumb_') . '.' . $ext;
                match ($ext) {
                    'jpg', 'jpeg' => imagejpeg($thumb, $tempFile, 85),
                    'png'         => imagepng($thumb, $tempFile, 8),
                    'gif'         => imagegif($thumb, $tempFile),
                    'webp'        => imagewebp($thumb, $tempFile, 85),
                    default       => null,
                };

                $thumbPath = $dir . '/' . $baseName . '-' . $sizeName . '.' . $ext;
                $this->storage->putFile($thumbPath, $tempFile);
                @unlink($tempFile);
                imagedestroy($thumb);
                $thumbnails[$sizeName] = $thumbPath;
            }

            imagedestroy($sourceImage);
            return !empty($thumbnails) ? $thumbnails : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
