<?php
declare(strict_types=1);

namespace TypeDock\Media;

use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use TypeDock\Contract\MediaProcessor;
use TypeDock\Contract\StorageDriver;

class MediaService
{
    /** @var MediaProcessor[] */
    private array $processors = [];

    // SVG is intentionally excluded: uploads live under /uploads (same origin),
    // and SVG can embed <script>/CSS that runs in that origin. Re-enabling it
    // requires either a sanitiser (e.g. enshrined/svg-sanitize) or serving
    // uploads from a separate cookieless/static origin.
    private const DEFAULT_ALLOWED_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
    ];

    private const DEFAULT_ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

    private const IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** MIME types that get a WebP sibling alongside the original. */
    private const WEBP_SIBLING_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const THUMBNAIL_SIZES = ['sm' => 300, 'md' => 768, 'lg' => 1200];

    /** Cap the stored original's longest side. WordPress uses 2560. */
    private int $maxImageWidth  = 2560;
    private int $maxImageHeight = 2560;

    private int $jpegQuality = 85;
    private int $webpQuality = 82;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly StorageDriver $storage
    ) {}

    /**
     * Override JPEG / WebP encode quality. Plugins like ImageOptimizer call
     * this during register() to tighten compression without having to hook
     * the low-level Intervention pipeline.
     */
    public function setImageQualities(int $jpegQuality, int $webpQuality): void
    {
        $this->jpegQuality = max(40, min(95, $jpegQuality));
        $this->webpQuality = max(40, min(95, $webpQuality));
    }

    /** Override the longest-edge cap applied to uploaded originals. */
    public function setMaxImageSize(int $maxWidth, int $maxHeight): void
    {
        $this->maxImageWidth  = max(400, $maxWidth);
        $this->maxImageHeight = max(400, $maxHeight);
    }

    /**
     * Register a post-upload processor. Processors run against the source
     * temp file before any image processing or storage write. They may
     * re-encode / optimise and return a replacement path.
     */
    public function addProcessor(MediaProcessor $processor): void
    {
        $this->processors[] = $processor;
    }

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
        if (!in_array($mimeType, $this->allowedMimeTypes(), true)) {
            throw new \TypeDock\Exception\ValidationException(
                ['file' => ['This file format is not supported.']]
            );
        }

        $originalName = (string) $file['name'];
        $ext          = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions(), true)) {
            throw new \TypeDock\Exception\ValidationException(
                ['file' => ['This file extension is not supported.']]
            );
        }

        // Plugin-contributed processors get first crack at the tmp file. They
        // may re-encode/compress and return a replacement path; any failure
        // is logged but does not block the upload.
        foreach ($this->processors as $processor) {
            try {
                $replacement = $processor->process($file['tmp_name'], $mimeType);
                if ($replacement !== '' && is_file($replacement)) {
                    $file['tmp_name'] = $replacement;
                }
            } catch (\Throwable $e) {
                error_log('[TypeDock] Media processor failed: ' . $e->getMessage());
            }
        }

        $safeName     = \Ramsey\Uuid\Uuid::uuid7()->toString() . '.' . $ext;
        $folder       = '/' . trim($folder, '/');
        $year         = date('Y');
        $month        = date('m');
        $storagePath  = ltrim($folder . '/' . $year . '/' . $month . '/' . $safeName, '/');

        $width      = null;
        $height     = null;
        $thumbnails = null;
        $fileSize   = (int) $file['size'];

        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            // Process with Intervention: orient from EXIF, downscale if huge,
            // strip metadata via re-encode, then generate thumbnails and WebP siblings.
            $processed = $this->processImage($file['tmp_name'], $storagePath, $mimeType, $ext);
            if ($processed !== null) {
                $width      = $processed['width'];
                $height     = $processed['height'];
                $thumbnails = $processed['thumbnails'];
                $fileSize   = $processed['file_size'];
            } else {
                // Intervention failed — fall back to storing the original as-is.
                $this->storeFile($storagePath, $file['tmp_name']);
                [$width, $height] = $this->getImageDimensions($file['tmp_name']);
            }
        } else {
            $this->storeFile($storagePath, $file['tmp_name']);
        }

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
            $fileSize,
            $width,
            $height,
            null,
            null,
            null,
            null,
            $folder . '/' . $year . '/' . $month,
            $thumbnails !== null ? json_encode($thumbnails) : null,
            $uploadedBy,
            $now,
        ]);

        return $this->find($id);
    }

    /**
     * Claim a media row — and with it a storage path and public URL — for a
     * file that has not been downloaded yet.
     *
     * This is what lets the importer write a post body exactly once: the URL
     * an image will have is known while the body is still being built, so
     * nothing has to go back and rewrite thousands of posts after the
     * downloads finish. Repeated calls for the same source return the same
     * row, which is also how one image used by fifty posts is fetched once.
     *
     * Returns null when the URL carries no extension we are willing to store;
     * the caller should leave the original URL in place rather than commit to
     * a path whose format we would only learn later.
     *
     * @return array<string, mixed>|null
     */
    public function reserve(string $sourceUrl, ?string $batchId = null): ?array
    {
        $sourceUrl = trim($sourceUrl);
        $hash      = hash('sha256', $sourceUrl);

        $stmt = $this->pdo->prepare('SELECT id FROM media WHERE source_hash = ? LIMIT 1');
        $stmt->execute([$hash]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return $this->find((string) $existing);
        }

        $path = (string) parse_url($sourceUrl, PHP_URL_PATH);
        $name = basename($path);
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $this->allowedExtensions(), true)) {
            return null;
        }

        $id          = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now         = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $folder      = '/import/' . self::sourceDateFolder($path);
        $storagePath = ltrim($folder . '/' . $id . '.' . $ext, '/');

        $this->pdo->prepare(
            'INSERT INTO media (id, path, original_filename, mime_type, file_size, folder,
                                source_url, source_hash, status, import_batch_id, created_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $storagePath,
            $name !== '' ? $name : $id . '.' . $ext,
            self::mimeForExtension($ext),
            $folder,
            $sourceUrl,
            $hash,
            'pending',
            $batchId,
            $now,
        ]);

        return $this->find($id);
    }

    /**
     * Reserve a row for an asset the source system identifies by id.
     *
     * The id is what a featured image or a gallery points at, so it has to be
     * a key we can look up later rather than something held in memory for the
     * duration of an import that may span dozens of requests.
     *
     * Deduplication still runs on the URL underneath, so an image used both in
     * a post body and as its featured image is one row and one download.
     *
     * @return array<string, mixed>|null Null when the URL is not something we store.
     */
    public function registerExternal(
        string $importerKey,
        string $externalId,
        string $sourceUrl,
        ?string $batchId = null,
    ): ?array {
        $existing = $this->findByExternalId($importerKey, $externalId);
        if ($existing !== null) {
            return $existing;
        }

        $row = $this->reserve($sourceUrl, $batchId);
        if ($row === null) {
            return null;
        }

        // `AND external_id IS NULL` guards the case where two source assets
        // share one URL: the row keeps the first id rather than silently
        // changing which asset it answers to.
        $this->pdo->prepare(
            'UPDATE media SET external_source = ?, external_id = ? WHERE id = ? AND external_id IS NULL'
        )->execute([$importerKey, $externalId, $row['id']]);

        return $this->find((string) $row['id']);
    }

    /** @return array<string, mixed>|null */
    public function findByExternalId(string $importerKey, string $externalId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM media WHERE external_source = ? AND external_id = ? LIMIT 1'
        );
        $stmt->execute([$importerKey, $externalId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : $this->find((string) $id);
    }

    /**
     * Keep imported files under the year/month they had on the source site.
     *
     * An asset uploaded in 2003 can belong to a post dated 2010, so the source
     * path is the only honest answer — and keeping it makes old media URLs
     * mappable to new ones. Falls back to today when the source URL says
     * nothing.
     */
    private static function sourceDateFolder(string $sourcePath): string
    {
        if (preg_match('#/(\d{4})/(\d{2})(?:/|$)#', $sourcePath, $m) === 1) {
            return $m[1] . '/' . $m[2];
        }

        return date('Y') . '/' . date('m');
    }

    /**
     * Put a downloaded file into a reserved row's storage path and mark it
     * ready. Safe to run twice — the second call simply overwrites the same
     * path with the same bytes, which is what at-least-once delivery needs.
     *
     * @return array<string, mixed>
     */
    public function fulfil(string $mediaId, string $tmpFile): array
    {
        $media = $this->find($mediaId);
        if ($media === null) {
            throw new \RuntimeException("Media row not found: {$mediaId}");
        }

        $mimeType = $this->detectMimeType($tmpFile);
        if (!in_array($mimeType, $this->allowedMimeTypes(), true)) {
            throw new \RuntimeException("Refusing {$mimeType} from {$media['source_url']}.");
        }

        $storagePath = (string) $media['path'];
        $ext         = strtolower(pathinfo($storagePath, PATHINFO_EXTENSION));
        if (self::mimeForExtension($ext) !== $mimeType) {
            // The reserved path — already baked into published post bodies —
            // promises a format the response did not deliver. Serving a PNG
            // as .jpg is the kind of thing that works everywhere until it
            // doesn't, so treat it as a failure the operator can see.
            throw new \RuntimeException(
                "{$media['source_url']} returned {$mimeType} but the reserved path expects .{$ext}."
            );
        }

        foreach ($this->processors as $processor) {
            try {
                $replacement = $processor->process($tmpFile, $mimeType);
                if ($replacement !== '' && is_file($replacement)) {
                    $tmpFile = $replacement;
                }
            } catch (\Throwable $e) {
                error_log('[TypeDock] Media processor failed: ' . $e->getMessage());
            }
        }

        $width      = null;
        $height     = null;
        $thumbnails = null;
        $fileSize   = (int) filesize($tmpFile);

        if (in_array($mimeType, self::IMAGE_MIME_TYPES, true)) {
            $processed = $this->processImage($tmpFile, $storagePath, $mimeType, $ext);
            if ($processed !== null) {
                $width      = $processed['width'];
                $height     = $processed['height'];
                $thumbnails = $processed['thumbnails'];
                $fileSize   = $processed['file_size'];
            } else {
                $this->storeFile($storagePath, $tmpFile);
                [$width, $height] = $this->getImageDimensions($tmpFile);
            }
        } else {
            $this->storeFile($storagePath, $tmpFile);
        }

        $this->pdo->prepare(
            "UPDATE media SET mime_type = ?, file_size = ?, width = ?, height = ?, thumbnails = ?, status = 'ready'
              WHERE id = ?"
        )->execute([
            $mimeType,
            $fileSize,
            $width,
            $height,
            $thumbnails !== null ? json_encode($thumbnails) : null,
            $mediaId,
        ]);

        return $this->find($mediaId);
    }

    /**
     * Park a media row whose file could not be fetched. The row is kept, not
     * deleted: it records where the file was supposed to come from, which is
     * what an operator needs to retry or upload a replacement.
     */
    public function markFailed(string $mediaId): void
    {
        $this->pdo->prepare("UPDATE media SET status = 'failed' WHERE id = ?")->execute([$mediaId]);
    }

    private static function mimeForExtension(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'pdf'         => 'application/pdf',
            default       => 'application/octet-stream',
        };
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

        $this->storage->delete((string) $media['path']);

        if (!empty($media['thumbnails'])) {
            $thumbnails = json_decode((string) $media['thumbnails'], true);
            if (is_array($thumbnails)) {
                foreach ($thumbnails as $path) {
                    if (is_string($path) && $path !== '') {
                        $this->storage->delete($path);
                    }
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

    /**
     * @return array<int, string>
     */
    private function allowedMimeTypes(): array
    {
        $configured = config('filesystems.allowed_mime_types', self::DEFAULT_ALLOWED_MIME_TYPES);
        if (!is_array($configured) || $configured === []) {
            return self::DEFAULT_ALLOWED_MIME_TYPES;
        }

        return array_values(array_filter($configured, 'is_string'));
    }

    /**
     * @return array<int, string>
     */
    private function allowedExtensions(): array
    {
        $configured = config('filesystems.allowed_extensions', self::DEFAULT_ALLOWED_EXTENSIONS);
        if (!is_array($configured) || $configured === []) {
            return self::DEFAULT_ALLOWED_EXTENSIONS;
        }

        return array_values(array_map(
            static fn (string $ext): string => strtolower(ltrim($ext, '.')),
            array_filter($configured, 'is_string')
        ));
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
     * Load the upload with Intervention, apply EXIF orientation, downscale
     * if larger than MAX_IMAGE_WIDTH/HEIGHT, strip metadata via re-encode,
     * then generate size variants and WebP siblings.
     *
     * @return array{width:int, height:int, file_size:int, thumbnails:array<string,string>|null}|null
     */
    private function processImage(string $tmpPath, string $storagePath, string $mimeType, string $ext): ?array
    {
        try {
            $manager = $this->imageManager();
            $image   = $manager->read($tmpPath);
            $image->orient();

            if ($image->width() > $this->maxImageWidth || $image->height() > $this->maxImageHeight) {
                $image->scaleDown(width: $this->maxImageWidth, height: $this->maxImageHeight);
            }

            $encoded = $this->encodeForMime($image, $mimeType);
            $this->putEncoded($storagePath, $encoded);

            $width  = $image->width();
            $height = $image->height();

            $thumbnails = $this->generateVariants($image, $storagePath, $mimeType, $ext);

            return [
                'width'      => $width,
                'height'     => $height,
                'file_size'  => strlen((string) $encoded),
                'thumbnails' => $thumbnails,
            ];
        } catch (\Throwable $e) {
            error_log('[TypeDock] Image processing failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate size thumbnails (sm/md/lg) and, for JPEG/PNG sources, WebP
     * siblings of the original and every thumbnail. Returns a flat map
     * {sm, md, lg, original_webp, sm_webp, md_webp, lg_webp} with only the
     * keys that were actually produced.
     *
     * @return array<string, string>|null
     */
    private function generateVariants(
        ImageInterface $source,
        string $storagePath,
        string $mimeType,
        string $ext
    ): ?array {
        $dir      = dirname($storagePath);
        $baseName = pathinfo($storagePath, PATHINFO_FILENAME);
        $dir      = $dir === '.' ? '' : $dir;
        $prefix   = $dir === '' ? $baseName : $dir . '/' . $baseName;

        $emitWebp = in_array($mimeType, self::WEBP_SIBLING_MIME_TYPES, true);
        $variants = [];

        if ($emitWebp) {
            $webpPath = $prefix . '.webp';
            $this->putEncoded($webpPath, $source->encode(new WebpEncoder(quality: $this->webpQuality)));
            $variants['original_webp'] = $webpPath;
        }

        foreach (self::THUMBNAIL_SIZES as $sizeName => $maxWidth) {
            if ($source->width() <= $maxWidth) {
                continue;
            }

            $thumb = clone $source;
            $thumb->scaleDown(width: $maxWidth);

            $thumbPath = $prefix . '-' . $sizeName . '.' . $ext;
            $this->putEncoded($thumbPath, $this->encodeForMime($thumb, $mimeType));
            $variants[$sizeName] = $thumbPath;

            if ($emitWebp) {
                $thumbWebpPath = $prefix . '-' . $sizeName . '.webp';
                $this->putEncoded(
                    $thumbWebpPath,
                    $thumb->encode(new WebpEncoder(quality: $this->webpQuality))
                );
                $variants[$sizeName . '_webp'] = $thumbWebpPath;
            }
        }

        return $variants === [] ? null : $variants;
    }

    private function encodeForMime(ImageInterface $image, string $mimeType): EncodedImageInterface
    {
        return match ($mimeType) {
            'image/jpeg' => $image->encode(new JpegEncoder(quality: $this->jpegQuality)),
            'image/png'  => $image->encode(new PngEncoder()),
            'image/webp' => $image->encode(new WebpEncoder(quality: $this->webpQuality)),
            // GIF: encode via extension-based shortcut (handles animated frames).
            default      => $image->encodeByExtension('gif'),
        };
    }

    /** Same contract as putEncoded(): a failed write must not look like a success. */
    private function storeFile(string $path, string $localPath): void
    {
        if (!$this->storage->putFile($path, $localPath)) {
            throw new \RuntimeException("Could not write {$path} to storage.");
        }
    }

    private function putEncoded(string $path, EncodedImageInterface $encoded): void
    {
        // A storage write can fail for reasons that have nothing to do with
        // the image — a full disk, a read-only uploads directory, an expired
        // bucket credential. Ignoring the result records a media row that
        // says "ready" and points at a file that was never written, which is
        // indistinguishable from a working import until someone loads the page.
        if (!$this->storage->put($path, (string) $encoded)) {
            throw new \RuntimeException("Could not write {$path} to storage.");
        }
    }

    private function imageManager(): ImageManager
    {
        return extension_loaded('imagick') ? ImageManager::imagick() : ImageManager::gd();
    }
}
