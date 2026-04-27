<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Theme;

use Latte\CompileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeDock\Theme\LatteFactory;

final class LatteCompileTest extends TestCase
{
    #[DataProvider('templateProvider')]
    public function testProjectTemplatesCompile(string $path): void
    {
        $factory = new LatteFactory(TYPEDOCK_ROOT . '/themes', 'default');
        $engine = $factory->getEngine();

        try {
            $compiled = $engine->compile($path);
        } catch (CompileException $e) {
            $this->fail($path . ': ' . $e->getMessage());
        }

        $this->assertStringContainsString('class Template', $compiled, $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function templateProvider(): iterable
    {
        $roots = [
            TYPEDOCK_ROOT . '/admin/layouts',
            TYPEDOCK_ROOT . '/admin/pages',
            TYPEDOCK_ROOT . '/themes/default',
            TYPEDOCK_ROOT . '/themes/kinari',
            TYPEDOCK_ROOT . '/themes/northline',
            TYPEDOCK_ROOT . '/themes/kawara',
        ];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'latte') {
                    continue;
                }
                $path = $file->getPathname();
                yield str_replace(TYPEDOCK_ROOT . '/', '', $path) => [$path];
            }
        }
    }
}
