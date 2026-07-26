<?php
declare(strict_types=1);

namespace TypeDock\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\Migration\Migrator;
use TypeDock\Media\MediaService;
use TypeDock\Storage\LocalStorage;

/**
 * The reserve/fulfil split is what lets an imported post body be written once
 * and never revisited, so it is worth pinning down without a network in the
 * picture.
 */
final class MediaReserveFulfilTest extends TestCase
{
    private string $sqlitePath;
    private string $storageRoot;
    private \PDO $pdo;
    private MediaService $media;

    protected function setUp(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('Image processing needs GD.');
        }

        $this->sqlitePath  = sys_get_temp_dir() . '/typedock-media-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->storageRoot = sys_get_temp_dir() . '/typedock-media-files-' . bin2hex(random_bytes(6));

        $this->pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $result = (new Migrator($this->pdo, 'sqlite', TYPEDOCK_ROOT . '/migrations'))->migrate();
        $this->assertSame([], $result['errors'], 'Migration errors: ' . json_encode($result['errors']));

        $this->media = new MediaService(
            $this->pdo,
            new LocalStorage(['root' => $this->storageRoot, 'url' => 'https://example.test/uploads'])
        );
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
        if (is_dir($this->storageRoot)) {
            exec('rm -rf ' . escapeshellarg($this->storageRoot));
        }
    }

    public function testReserveFixesTheFinalUrlBeforeAnythingIsDownloaded(): void
    {
        $reserved = $this->media->reserve('https://old.example.com/uploads/photo.jpg', 'batch-1');

        $this->assertNotNull($reserved);
        $this->assertSame('pending', $reserved['status']);
        $this->assertSame(0, (int) $reserved['file_size']);
        $this->assertStringStartsWith('https://example.test/uploads/import/', (string) $reserved['url']);
        $this->assertStringEndsWith('.jpg', (string) $reserved['url']);
        $this->assertFalse(is_file($this->storageRoot . '/' . $reserved['path']), 'Nothing on disk yet');
    }

    public function testReserveIsIdempotentPerSourceUrl(): void
    {
        $first  = $this->media->reserve('https://old.example.com/uploads/photo.jpg');
        $second = $this->media->reserve('https://old.example.com/uploads/photo.jpg');

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM media')->fetchColumn());
    }

    public function testReserveRefusesUrlsWithNoUsableExtension(): void
    {
        $this->assertNull($this->media->reserve('https://old.example.com/download?id=42'));
        $this->assertNull($this->media->reserve('https://old.example.com/evil.svg'), 'SVG stays excluded');
        $this->assertNull($this->media->reserve('https://old.example.com/payload.php'));
    }

    public function testFulfilWritesTheFileToTheReservedPathAndMarksItReady(): void
    {
        $reserved = $this->media->reserve('https://old.example.com/uploads/photo.jpg');
        $tmp      = $this->createJpeg(120, 80);

        try {
            $fulfilled = $this->media->fulfil((string) $reserved['id'], $tmp);
        } finally {
            @unlink($tmp);
        }

        $this->assertSame('ready', $fulfilled['status']);
        $this->assertSame($reserved['path'], $fulfilled['path'], 'The path promised at reserve time must not move');
        $this->assertSame($reserved['url'], $fulfilled['url']);
        $this->assertSame(120, (int) $fulfilled['width']);
        $this->assertSame(80, (int) $fulfilled['height']);
        $this->assertGreaterThan(0, (int) $fulfilled['file_size']);
        $this->assertFileExists($this->storageRoot . '/' . $fulfilled['path']);
    }

    public function testFulfilRefusesContentThatDoesNotMatchTheReservedExtension(): void
    {
        $reserved = $this->media->reserve('https://old.example.com/uploads/photo.jpg');
        $tmp      = $this->createPng();

        try {
            $this->expectExceptionMessageMatches('/reserved path expects \.jpg/');
            $this->media->fulfil((string) $reserved['id'], $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testMarkFailedKeepsTheRowAndItsSourceUrl(): void
    {
        $reserved = $this->media->reserve('https://old.example.com/uploads/photo.jpg');

        $this->media->markFailed((string) $reserved['id']);

        $row = $this->media->find((string) $reserved['id']);
        $this->assertSame('failed', $row['status']);
        $this->assertSame('https://old.example.com/uploads/photo.jpg', $row['source_url']);
    }

    private function createJpeg(int $width, int $height): string
    {
        $path  = sys_get_temp_dir() . '/typedock-img-' . bin2hex(random_bytes(4)) . '.jpg';
        $image = imagecreatetruecolor($width, $height);
        imagejpeg($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function createPng(): string
    {
        $path  = sys_get_temp_dir() . '/typedock-img-' . bin2hex(random_bytes(4)) . '.png';
        $image = imagecreatetruecolor(10, 10);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
