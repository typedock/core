<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TypeDock\Update\InstallationProfile;
use TypeDock\Update\PackageManifest;
use TypeDock\Update\PreflightChecker;

final class PreflightCheckerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/typedock-preflight-' . bin2hex(random_bytes(6));
        foreach (['storage/tmp', 'storage/backups', 'public', 'src', 'vendor'] as $dir) {
            mkdir($this->root . '/' . $dir, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    public function testSourceManagedInstallGetsDeploymentHint(): void
    {
        $profile = new InstallationProfile($this->root, $this->root . '/public', 'source', true);
        $report = (new PreflightChecker($profile, $this->manifest()))->check();

        self::assertTrue($report->canApplyUpdates());
        self::assertTrue($this->hasIssue($report->issues, 'Installation mode', 'warning'));
    }

    public function testZipManagedInstallPassesLocalModeCheck(): void
    {
        $profile = new InstallationProfile($this->root, $this->root . '/public', 'zip', true);
        $report = (new PreflightChecker($profile, $this->manifest()))->check();

        self::assertTrue($this->hasIssue($report->issues, 'Installation mode', 'ok'));
    }

    public function testReportsDatabaseBackupHintForMysql(): void
    {
        $profile = new InstallationProfile($this->root, $this->root . '/public', 'zip', true);
        $report = (new PreflightChecker($profile, $this->manifest(), null, ['driver' => 'mysql']))->check();

        self::assertTrue($this->hasIssue($report->issues, 'Database', 'warning'));
    }

    /**
     * @param list<object> $issues
     */
    private function hasIssue(array $issues, string $label, string $severity): bool
    {
        foreach ($issues as $issue) {
            if ($issue->label === $label && $issue->severity === $severity) {
                return true;
            }
        }
        return false;
    }

    private function manifest(): PackageManifest
    {
        return new PackageManifest(1, '0.1.0', ['src', 'vendor'], [], [], []);
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
