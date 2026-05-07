<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TypeDock\Update\AgentUpdateContext;
use TypeDock\Update\InstallationProfile;
use TypeDock\Update\PackageManifest;
use TypeDock\Update\PreflightChecker;

final class AgentUpdateContextTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/typedock-agent-context-' . bin2hex(random_bytes(6));
        foreach (['storage/tmp', 'storage/backups', 'public', 'src'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testBuildsMachineReadableContextAndPrompt(): void
    {
        $profile = new InstallationProfile($this->root, $this->root . '/public', 'source', true);
        $manifest = new PackageManifest(1, '0.8.0', ['src'], ['default'], ['form'], []);
        $report = (new PreflightChecker($profile, $manifest))->check();
        $context = new AgentUpdateContext($report);

        $data = json_decode($context->toJson(), true);

        self::assertSame(1, $data['schema_version']);
        self::assertSame('source', $data['installation']['mode']);
        self::assertSame(['default'], $data['package']['bundled_themes']);
        self::assertStringContainsString('You are updating a TypeDock CMS installation', $context->prompt());
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
