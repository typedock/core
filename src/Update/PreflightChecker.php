<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class PreflightChecker
{
    public function __construct(
        private readonly InstallationProfile $profile,
        private readonly PackageManifest $currentManifest,
        private readonly ?PackageManifest $targetManifest = null,
        private readonly ?array $db = null,
    ) {}

    public static function fromRuntime(?PackageManifest $targetManifest = null): self
    {
        $profile = InstallationProfile::fromRuntime();
        $manifestPath = (string) \config('update.manifest_path', $profile->root . '/typedock-package.json');
        $db = is_file($profile->root . '/config/database.php') ? require $profile->root . '/config/database.php' : null;
        return new self($profile, PackageManifest::fromFile($manifestPath), $targetManifest, is_array($db) ? $db : null);
    }

    public function check(): PreflightReport
    {
        $issues = [];
        $target = $this->targetManifest ?? $this->currentManifest;

        if (!$this->profile->selfUpdateEnabled) {
            $issues[] = PreflightIssue::warning('Agent updates', 'Agent-assisted update tools are disabled by configuration.');
        } elseif ($this->profile->mode !== 'zip') {
            $issues[] = PreflightIssue::warning(
                'Installation mode',
                "This install is managed as {$this->profile->mode}; a coding agent should update it through the deployment workflow."
            );
        } else {
            $issues[] = PreflightIssue::ok('Installation mode', 'Zip-managed install; a coding agent can update files in place after backup.');
        }

        $issues[] = version_compare(PHP_VERSION, '8.2.0', '>=')
            ? PreflightIssue::ok('PHP version', 'PHP ' . PHP_VERSION)
            : PreflightIssue::error('PHP version', 'TypeDock updates require PHP 8.2 or newer.');

        $issues[] = extension_loaded('zip')
            ? PreflightIssue::ok('Zip extension', 'Safe archive inspection and staging are available.')
            : ($this->profile->isZipManaged()
                ? PreflightIssue::error('Zip extension', 'PHP ext-zip is required for in-place updates.')
                : PreflightIssue::warning('Zip extension', 'PHP ext-zip is unavailable; deployment tooling must inspect release archives.'));

        $issues[] = extension_loaded('sodium')
            ? PreflightIssue::ok('Sodium extension', 'Core can verify Ed25519 release signatures.')
            : ($this->profile->isZipManaged()
                ? PreflightIssue::error('Sodium extension', 'PHP ext-sodium is required for in-place updates.')
                : PreflightIssue::warning('Sodium extension', 'Deployment tooling must verify release signatures.'));

        $issues[] = extension_loaded('curl')
            ? PreflightIssue::ok('cURL extension', 'Core can use DNS-pinned HTTPS update downloads.')
            : ($this->profile->isZipManaged()
                ? PreflightIssue::error('cURL extension', 'PHP ext-curl is required for secure in-place update downloads.')
                : PreflightIssue::warning('cURL extension', 'Deployment tooling must download the release package.'));

        $issues = array_merge($issues, $this->pathChecks());
        if ($this->db !== null) {
            $issues[] = $this->databaseBackupCheck($this->db);
        }

        $ownership = (new ExtensionOwnershipScanner($this->profile->root, $this->currentManifest))->scan($target);
        foreach ($ownership as $row) {
            if ($row['status'] === 'collision') {
                $issues[] = PreflightIssue::error(
                    ucfirst($row['type']) . ' collision',
                    "{$row['slug']}: {$row['message']}"
                );
            } elseif ($row['status'] === 'modified') {
                $issues[] = PreflightIssue::warning(
                    ucfirst($row['type']) . ' modified',
                    "{$row['slug']}: {$row['message']}"
                );
            } elseif ($row['status'] === 'managed-untracked') {
                $issues[] = PreflightIssue::warning(
                    ucfirst($row['type']) . ' untracked',
                    "{$row['slug']}: {$row['message']}"
                );
            }
        }

        return new PreflightReport($this->profile, $this->currentManifest, $issues, $ownership);
    }

    /**
     * @return list<PreflightIssue>
     */
    private function pathChecks(): array
    {
        $issues = [];
        $root = $this->profile->root;
        $publicDir = $this->profile->publicDir;

        $issues[] = $this->writableDir('Application root', $root);
        $issues[] = $this->writableDir('Storage tmp', $root . '/storage/tmp');
        $issues[] = $this->writableDir('Backup directory', $root . '/storage/backups');

        if ($this->profile->isSplitPublic()) {
            $issues[] = is_dir($publicDir) && is_writable($publicDir)
                ? PreflightIssue::warning('Public directory', 'Split public install detected; public assets will be synced instead of swapped.')
                : PreflightIssue::error('Public directory', 'Split public directory is not writable: ' . $publicDir);
        } else {
            $issues[] = $this->writableDir('Public directory', $publicDir);
        }

        $managedPaths = array_values(array_unique(array_merge(
            $this->currentManifest->managedPaths,
            ($this->targetManifest ?? $this->currentManifest)->managedPaths,
        )));
        foreach ($managedPaths as $path) {
            $full = $this->managedPath($path);
            if (!file_exists($full)) {
                $issues[] = PreflightIssue::warning('Managed path', "{$path} does not exist in this install.");
                continue;
            }
            if (!is_writable($full)) {
                $issues[] = PreflightIssue::error('Managed path', "{$path} is not writable.");
            }
        }

        return $issues;
    }

    private function managedPath(string $path): string
    {
        if ($this->profile->isSplitPublic() && str_starts_with($path, 'public/')) {
            return $this->profile->publicDir . '/' . substr($path, strlen('public/'));
        }

        return $this->profile->root . '/' . $path;
    }

    private function writableDir(string $label, string $path): PreflightIssue
    {
        if (!is_dir($path)) {
            return PreflightIssue::warning($label, 'Directory is missing: ' . $path);
        }
        if (!is_writable($path)) {
            return PreflightIssue::warning($label, 'Directory is not writable by PHP; the coding agent may still update it through SSH/FTP/deployment.');
        }
        return PreflightIssue::ok($label, 'Writable: ' . $path);
    }

    /**
     * @param array<string, mixed> $db
     */
    private function databaseBackupCheck(array $db): PreflightIssue
    {
        $driver = (string) ($db['driver'] ?? 'mysql');
        if ($driver !== 'sqlite') {
            return PreflightIssue::warning(
                'Database',
                strtoupper($driver) . ' detected. The coding agent must create a database backup using the host/deployment tooling before migrations.'
            );
        }

        $path = (string) ($db['sqlite_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            return PreflightIssue::warning('Database', 'SQLite database file is missing or not yet created: ' . $path);
        }
        if (!is_readable($path) || !is_writable($path)) {
            return PreflightIssue::warning('Database', 'SQLite database file should be readable and writable before an update: ' . $path);
        }

        return PreflightIssue::ok('Database', 'SQLite database can be backed up by copying the file.');
    }
}
