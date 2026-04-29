<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\PluginInstaller;

/**
 * The installer is the trust boundary for admin-uploaded plugin zips. We
 * pin its rejection rules — slug regex, public/*.php denylist, path
 * traversal, missing manifest — and confirm the happy path lands the
 * plugin at plugins/<slug>/ with the manifest at the root, regardless
 * of whether the archive used a wrapper directory or not.
 */
final class PluginInstallerTest extends TestCase
{
    private string $pluginsDir = '';

    protected function setUp(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is required for the plugin installer.');
        }
        $this->pluginsDir = sys_get_temp_dir() . '/td-installer-' . bin2hex(random_bytes(4));
        mkdir($this->pluginsDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->pluginsDir !== '') {
            $this->rmrf($this->pluginsDir);
        }
    }

    public function test_installs_archive_with_wrapper_directory(): void
    {
        $zip = $this->makeZip([
            'demo-main/plugin.json' => $this->manifest('demo'),
            'demo-main/src/DemoPlugin.php' => "<?php\n",
            'demo-main/templates/x.latte' => 'hi',
        ]);

        $result = (new PluginInstaller($this->pluginsDir))->install($zip);

        self::assertSame('demo', $result['slug']);
        self::assertFalse($result['replaced']);
        self::assertFileExists($this->pluginsDir . '/demo/plugin.json');
        self::assertFileExists($this->pluginsDir . '/demo/src/DemoPlugin.php');
    }

    public function test_installs_archive_without_wrapper(): void
    {
        $zip = $this->makeZip([
            'plugin.json' => $this->manifest('flat'),
            'src/FlatPlugin.php' => "<?php\n",
        ]);

        $result = (new PluginInstaller($this->pluginsDir))->install($zip);
        self::assertSame('flat', $result['slug']);
        self::assertFileExists($this->pluginsDir . '/flat/plugin.json');
    }

    public function test_rejects_invalid_slug(): void
    {
        $zip = $this->makeZip([
            'plugin.json' => $this->manifest('Bad Slug!'),
        ]);
        $this->expectExceptionMessageMatches('/slug.*invalid/i');
        (new PluginInstaller($this->pluginsDir))->install($zip);
    }

    public function test_rejects_missing_manifest(): void
    {
        $zip = $this->makeZip([
            'wrap/src/foo.php' => "<?php\n",
        ]);
        $this->expectExceptionMessageMatches('/plugin\.json/');
        (new PluginInstaller($this->pluginsDir))->install($zip);
    }

    public function test_rejects_php_under_public(): void
    {
        $zip = $this->makeZip([
            'plugin.json' => $this->manifest('badpub'),
            'public/leak.php' => "<?php phpinfo();\n",
        ]);
        $this->expectExceptionMessageMatches('/public\//i');
        (new PluginInstaller($this->pluginsDir))->install($zip);
    }

    public function test_rejects_path_traversal(): void
    {
        $zip = $this->makeZip([
            'plugin.json' => $this->manifest('trav'),
            '../escape.txt' => 'nope',
        ]);
        $this->expectExceptionMessageMatches('/Unsafe path|escapes/i');
        (new PluginInstaller($this->pluginsDir))->install($zip);
    }

    public function test_refuses_overwrite_unless_opted_in(): void
    {
        $zip = $this->makeZip([
            'plugin.json' => $this->manifest('twice'),
        ]);
        $installer = new PluginInstaller($this->pluginsDir);
        $installer->install($zip);

        $this->expectExceptionMessageMatches('/already exists/i');
        $installer->install($zip);
    }

    public function test_overwrite_replaces_existing(): void
    {
        $installer = new PluginInstaller($this->pluginsDir);
        $installer->install($this->makeZip([
            'plugin.json' => $this->manifest('twice'),
            'old.txt' => 'v1',
        ]));
        $result = $installer->install(
            $this->makeZip([
                'plugin.json' => $this->manifest('twice'),
                'new.txt' => 'v2',
            ]),
            overwrite: true,
        );

        self::assertTrue($result['replaced']);
        self::assertFileDoesNotExist($this->pluginsDir . '/twice/old.txt');
        self::assertFileExists($this->pluginsDir . '/twice/new.txt');
    }

    private function manifest(string $slug): string
    {
        return json_encode([
            'slug' => $slug,
            'name' => $slug,
            'version' => '1.0.0',
            'main_class' => 'TypeDock\\Plugin\\Whatever\\Plugin',
            'autoload' => ['psr-4' => ['TypeDock\\Plugin\\Whatever\\' => 'src/']],
        ]);
    }

    /**
     * @param array<string, string> $entries
     */
    private function makeZip(array $entries): string
    {
        $path = sys_get_temp_dir() . '/td-zip-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE) !== true) {
            self::fail("Cannot create test zip at {$path}");
        }
        foreach ($entries as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();
        return $path;
    }

    private function rmrf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $f) {
            /** @var \SplFileInfo $f */
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
