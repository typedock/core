<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TypeDock\Update\ReleaseMetadata;
use TypeDock\Update\UpdatePackage;

final class UpdatePackageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typedock-package-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testExtractsSplitPackageAndVerifiesManifestHashes(): void
    {
        $zipPath = $this->dir . '/release.zip';
        $manifest = [
            'schema_version' => 1,
            'version' => '1.0.0-rc7',
            'managed_paths' => ['src', 'public/index.php'],
            'bundled_themes' => [],
            'bundled_plugins' => [],
            'file_hashes' => [
                'src/Test.php' => 'sha256:' . hash('sha256', '<?php return 7;'),
                'public/index.php' => 'sha256:' . hash('sha256', '<?php echo 7;'),
            ],
        ];
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE));
        $zip->addFromString('typedock-shared/typedock/src/Test.php', '<?php return 7;');
        $zip->addFromString('typedock-shared/public_html/index.php', '<?php echo 7;');
        $zip->addFromString(
            'typedock-shared/typedock/typedock-package.json',
            json_encode($manifest, JSON_THROW_ON_ERROR),
        );
        $zip->close();

        $prepared = (new UpdatePackage())->prepare(
            $zipPath,
            $this->dir . '/stage',
            $this->release((int) filesize($zipPath)),
        );

        self::assertSame('1.0.0-rc7', $prepared->manifest->version);
        self::assertFileExists($prepared->publicDir . '/index.php');
    }

    public function testRejectsArchiveTraversalBeforeExtraction(): void
    {
        $zipPath = $this->dir . '/unsafe.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::CREATE));
        $zip->addFromString('typedock-shared/../outside.php', '<?php');
        $zip->close();

        $this->expectExceptionMessage('unsafe archive path');
        (new UpdatePackage())->prepare(
            $zipPath,
            $this->dir . '/unsafe-stage',
            $this->release((int) filesize($zipPath)),
        );
    }

    private function release(int $size): ReleaseMetadata
    {
        return new ReleaseMetadata(
            1,
            'rc',
            '1.0.0-rc7',
            '',
            '8.2.0',
            '1.0.0-rc6',
            '',
            'https://example.com/release.zip',
            'https://example.com/release.zip.minisig',
            str_repeat('a', 64),
            $size,
            '',
            [],
            false,
            false,
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
