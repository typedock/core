<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TypeDock\Update\ExtensionOwnershipScanner;
use TypeDock\Update\PackageManifest;

final class ExtensionOwnershipScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/typedock-update-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/themes/default', 0775, true);
        mkdir($this->root . '/themes/custom', 0775, true);
        mkdir($this->root . '/plugins/form', 0775, true);

        file_put_contents($this->root . '/themes/default/theme.json', '{"name":"Default"}');
        file_put_contents($this->root . '/themes/custom/theme.json', '{"name":"Custom"}');
        file_put_contents($this->root . '/plugins/form/plugin.json', '{"name":"Form"}');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testClassifiesCleanBundledAndUserOwnedExtensions(): void
    {
        $manifest = $this->manifest([
            'themes/default/theme.json' => $this->hash('themes/default/theme.json'),
            'plugins/form/plugin.json' => $this->hash('plugins/form/plugin.json'),
        ]);

        $rows = (new ExtensionOwnershipScanner($this->root, $manifest))->scan();
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row['type'] . ':' . $row['slug']] = $row['status'];
        }

        self::assertSame('clean', $bySlug['theme:default']);
        self::assertSame('user-owned', $bySlug['theme:custom']);
        self::assertSame('clean', $bySlug['plugin:form']);
    }

    public function testDetectsModifiedBundledExtension(): void
    {
        $manifest = $this->manifest([
            'themes/default/theme.json' => $this->hash('themes/default/theme.json'),
        ]);
        file_put_contents($this->root . '/themes/default/theme.json', '{"name":"Changed"}');

        $rows = (new ExtensionOwnershipScanner($this->root, $manifest))->scan();
        $default = array_values(array_filter($rows, fn(array $row): bool => $row['type'] === 'theme' && $row['slug'] === 'default'))[0];

        self::assertSame('modified', $default['status']);
    }

    public function testDetectsTargetOwnershipCollision(): void
    {
        $current = $this->manifest([]);
        $target = new PackageManifest(1, '0.2.0', [], ['default', 'custom'], ['form'], []);

        $rows = (new ExtensionOwnershipScanner($this->root, $current))->scan($target);
        $custom = array_values(array_filter($rows, fn(array $row): bool => $row['type'] === 'theme' && $row['slug'] === 'custom'))[0];

        self::assertSame('collision', $custom['status']);
    }

    public function testPreservesPluginRemovedFromBundledPackage(): void
    {
        $current = $this->manifest([
            'plugins/form/plugin.json' => $this->hash('plugins/form/plugin.json'),
        ]);
        $target = new PackageManifest(1, '0.2.0', [], ['default'], [], []);

        $rows = (new ExtensionOwnershipScanner($this->root, $current))->scan($target);
        $form = array_values(array_filter(
            $rows,
            fn(array $row): bool => $row['type'] === 'plugin' && $row['slug'] === 'form',
        ))[0];

        self::assertSame('removed-bundled', $form['status']);
    }

    /**
     * @param array<string, string> $hashes
     */
    private function manifest(array $hashes): PackageManifest
    {
        return new PackageManifest(1, '0.8.0', [], ['default'], ['form'], $hashes);
    }

    private function hash(string $relative): string
    {
        return 'sha256:' . hash_file('sha256', $this->root . '/' . $relative);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
