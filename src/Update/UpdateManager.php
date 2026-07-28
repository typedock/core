<?php
declare(strict_types=1);

namespace TypeDock\Update;

use TypeDock\Core\AssetPublisher;
use TypeDock\Core\CacheClearer;
use TypeDock\Core\Migration\Migrator;

final class UpdateManager
{
    private UpdateState $stateStore;

    /**
     * @param array<string, mixed> $databaseConfig
     * @param list<string> $trustedPublicKeys
     */
    public function __construct(
        private readonly InstallationProfile $profile,
        private readonly PackageManifest $currentManifest,
        private readonly \PDO $pdo,
        private readonly array $databaseConfig,
        private readonly string $tmpDir,
        private readonly string $backupDir,
        string $statePath,
        private readonly array $trustedPublicKeys,
    ) {
        $this->stateStore = new UpdateState($statePath);
    }

    public static function fromRuntime(\PDO $pdo): self
    {
        $profile = InstallationProfile::fromRuntime();
        $manifestPath = (string) \config('update.manifest_path', $profile->root . '/typedock-package.json');
        $database = (array) \config('database', []);
        return new self(
            $profile,
            PackageManifest::fromFile($manifestPath),
            $pdo,
            $database,
            (string) \config('update.tmp_dir', $profile->root . '/storage/tmp'),
            (string) \config('update.backup_dir', $profile->root . '/storage/backups'),
            (string) \config('update.state_path', $profile->root . '/storage/upgrade-state.json'),
            Trust::publicKeys(),
        );
    }

    /**
     * Download, authenticate, extract, and inspect an update without changing
     * the live application.
     *
     * @return array<string, mixed>
     */
    public function prepare(ReleaseMetadata $release): array
    {
        $this->assertZipManaged();
        $currentVersion = $this->currentVersion();
        $release->assertInstallableFrom($currentVersion);
        if ($this->trustedPublicKeys === []) {
            throw new \RuntimeException('TypeDock update signing keys are not configured.');
        }
        $free = @disk_free_space($this->profile->root);
        if (is_float($free) && $free < ($release->sizeBytes * 4)) {
            throw new \RuntimeException('At least four times the package size must be free for staging and rollback.');
        }

        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(5));
        $workDir = rtrim($this->tmpDir, '/') . '/update-' . $id;
        $stageDir = $workDir . '/stage';
        if (!mkdir($workDir, 0775, true) && !is_dir($workDir)) {
            throw new \RuntimeException('Unable to create the update work directory.');
        }

        $zipPath = $workDir . '/package.zip';
        $signaturePath = $workDir . '/package.zip.minisig';
        UpdateDownloader::download($release->zipUrl, $zipPath, 134_217_728, $release->sizeBytes);
        UpdateDownloader::download($release->signatureUrl, $signaturePath, 65_536);

        $verifier = new SignatureVerifier($this->trustedPublicKeys[0]);
        $verifier->verifySha256($zipPath, $release->sha256);
        $verifiedSigningKey = (new SignatureKeyring($this->trustedPublicKeys))
            ->verifyMinisign($zipPath, $signaturePath);

        $prepared = (new UpdatePackage())->prepare($zipPath, $stageDir, $release);
        $report = (new PreflightChecker(
            $this->profile,
            $this->currentManifest,
            $prepared->manifest,
            $this->databaseConfig,
        ))->check();
        if (!$report->canApplyUpdates()) {
            throw new \RuntimeException('Update preflight found a blocking filesystem or ownership conflict.');
        }

        $state = [
            'schema_version' => 1,
            'id' => $id,
            'token' => bin2hex(random_bytes(24)),
            'phase' => 'prepared',
            'current_version' => $currentVersion,
            'target_version' => $release->version,
            'verified_signing_key' => $verifiedSigningKey,
            'release' => $release->toArray(),
            'work_dir' => $workDir,
            'stage_dir' => $stageDir,
            'backup_dir' => rtrim($this->backupDir, '/') . '/pre-update-' . $id,
            'ownership' => $report->ownership,
            'swaps' => [],
            'database_backup' => null,
            'error' => null,
            'created_at' => gmdate(\DateTimeInterface::ATOM),
        ];
        $this->stateStore->write($state);
        $this->log($state, "Update package authenticated with signing key {$verifiedSigningKey} and staged.");
        return $state;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        return $this->stateStore->read();
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(string $token): array
    {
        $this->assertZipManaged();
        $lockPath = $this->profile->root . '/storage/upgrade.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new \RuntimeException('Another update process is already running.');
        }

        try {
            $state = $this->stateStore->requireToken($token);
            if (($state['phase'] ?? '') !== 'prepared') {
                throw new \RuntimeException('Only a prepared update can be applied.');
            }
            $release = ReleaseMetadata::fromJson(
                json_encode($state['release'], JSON_THROW_ON_ERROR),
                (string) (($state['release']['channel'] ?? '') ?: \config('update.channel', 'stable')),
            );
            $release->assertInstallableFrom($this->currentVersion());
            $prepared = (new UpdatePackage())->loadPrepared((string) $state['stage_dir'], $release);

            $report = (new PreflightChecker(
                $this->profile,
                $this->currentManifest,
                $prepared->manifest,
                $this->databaseConfig,
            ))->check();
            if (!$report->canApplyUpdates()) {
                throw new \RuntimeException('Update preflight no longer passes. No live files were changed.');
            }

            ignore_user_abort(true);
            $backupDir = (string) $state['backup_dir'];
            $databaseBackup = (new UpdateDatabaseBackup(
                $this->pdo,
                $this->databaseConfig,
                $this->profile->root,
            ))->create($backupDir . '/database');
            $state['database_backup'] = $databaseBackup;
            $state['phase'] = 'backup_complete';
            $this->stateStore->write($state);

            (new MaintenanceMode($this->profile->root))->enable(
                "Updating TypeDock to {$release->version}",
                (string) $state['token'],
            );
            $state['phase'] = 'swapping';
            $this->stateStore->write($state);

            foreach ($this->operations($prepared) as $operation) {
                $state = $this->swapOne($state, $operation);
            }

            $state['phase'] = 'migrating';
            $this->stateStore->write($state);
            $migrator = new Migrator(
                $this->pdo,
                (string) ($this->databaseConfig['driver'] ?? 'mysql'),
                $this->profile->root . '/migrations',
            );
            $migration = $migrator->migrate();
            if ($migration['errors'] !== []) {
                $first = $migration['errors'][0];
                throw new \RuntimeException(
                    "Migration {$first['version']} ({$first['name']}) failed: {$first['message']}"
                );
            }
            $state['migrations_applied'] = $migration['applied'];

            (new AssetPublisher($this->profile->root, $this->profile->publicDir))->publishAll();
            (new CacheClearer())->clearTemplateCaches();
            $this->verifyLiveFiles($prepared->manifest);

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            (new MaintenanceMode($this->profile->root))->disable();
            $state['phase'] = 'done';
            $state['completed_at'] = gmdate(\DateTimeInterface::ATOM);
            $this->stateStore->write($state);
            $this->log($state, "Update completed at {$release->version}.");
            return $state;
        } catch (\Throwable $e) {
            try {
                $state = $this->stateStore->read();
                if (is_array($state) && ($state['phase'] ?? '') !== 'prepared') {
                    $this->rollbackState($state, $e->getMessage());
                }
            } catch (\Throwable $rollbackError) {
                $state = $this->stateStore->read() ?? [];
                $state['phase'] = 'rollback_failed';
                $state['error'] = $e->getMessage();
                $state['rollback_error'] = $rollbackError->getMessage();
                $this->stateStore->write($state);
                $this->log($state, 'Rollback failed: ' . $rollbackError->getMessage());
                throw new \RuntimeException(
                    'Update failed and automatic rollback also failed: ' . $rollbackError->getMessage(),
                    0,
                    $e,
                );
            }
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rollback(string $token): array
    {
        $state = $this->stateStore->requireToken($token);
        if (!in_array((string) ($state['phase'] ?? ''), ['backup_complete', 'swapping', 'migrating', 'rollback_failed'], true)) {
            throw new \RuntimeException('This update is not in a recoverable phase.');
        }
        return $this->rollbackState($state, 'Rollback requested by an administrator.');
    }

    /**
     * @return list<array{logical:string,source:string,destination:string}>
     */
    private function operations(PreparedPackage $prepared): array
    {
        $operations = [];
        foreach ($prepared->manifest->managedPaths as $logical) {
            $operations[] = [
                'logical' => $logical,
                'source' => $this->packagePath($prepared, $logical),
                'destination' => $this->livePath($logical),
            ];
        }
        foreach ($prepared->manifest->bundledThemes as $slug) {
            $logical = 'themes/' . $slug;
            $operations[] = [
                'logical' => $logical,
                'source' => $prepared->appDir . '/' . $logical,
                'destination' => $this->profile->root . '/' . $logical,
            ];
        }
        foreach ($prepared->manifest->bundledPlugins as $slug) {
            $logical = 'plugins/' . $slug;
            $operations[] = [
                'logical' => $logical,
                'source' => $prepared->appDir . '/' . $logical,
                'destination' => $this->profile->root . '/' . $logical,
            ];
        }
        $operations[] = [
            'logical' => 'typedock-package.json',
            'source' => $prepared->appDir . '/typedock-package.json',
            'destination' => $this->profile->root . '/typedock-package.json',
        ];

        usort($operations, static function (array $a, array $b): int {
            $last = ['public/install.php' => 1, 'public/index.php' => 2, 'typedock-package.json' => 3];
            return ($last[$a['logical']] ?? 0) <=> ($last[$b['logical']] ?? 0);
        });
        return $operations;
    }

    /**
     * @param array<string, mixed> $state
     * @param array{logical:string,source:string,destination:string} $operation
     * @return array<string, mixed>
     */
    private function swapOne(array $state, array $operation): array
    {
        $source = $operation['source'];
        $destination = $operation['destination'];
        if (!file_exists($source)) {
            throw new \RuntimeException("Staged update path disappeared: {$operation['logical']}");
        }

        $backup = (string) $state['backup_dir'] . '/files/' . $operation['logical'];
        $this->ensureParent($backup);
        $this->ensureParent($destination);
        $hadLive = file_exists($destination);
        if ($hadLive && !rename($destination, $backup)) {
            throw new \RuntimeException("Unable to back up live path {$operation['logical']}.");
        }

        $state['swaps'][] = [
            'logical' => $operation['logical'],
            'destination' => $destination,
            'backup' => $backup,
            'had_live' => $hadLive,
        ];
        $this->stateStore->write($state);

        if (!rename($source, $destination)) {
            throw new \RuntimeException("Unable to activate staged path {$operation['logical']}.");
        }
        return $state;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function rollbackState(array $state, string $reason): array
    {
        $failedDir = (string) ($state['backup_dir'] ?? $this->backupDir . '/failed') . '/failed-new';
        $swaps = array_reverse((array) ($state['swaps'] ?? []));
        foreach ($swaps as $swap) {
            if (!is_array($swap)) {
                continue;
            }
            $destination = (string) ($swap['destination'] ?? '');
            $backup = (string) ($swap['backup'] ?? '');
            $logical = (string) ($swap['logical'] ?? 'unknown');
            if ($destination === '' || $backup === '') {
                continue;
            }
            if (file_exists($destination)) {
                $failed = $failedDir . '/' . $logical;
                $this->ensureParent($failed);
                if (!rename($destination, $failed)) {
                    throw new \RuntimeException("Unable to move failed update path {$logical} aside.");
                }
            }
            if ((bool) ($swap['had_live'] ?? false) && file_exists($backup) && !rename($backup, $destination)) {
                throw new \RuntimeException("Unable to restore live path {$logical}.");
            }
        }

        $databaseBackup = $state['database_backup'] ?? null;
        if (is_array($databaseBackup)) {
            (new UpdateDatabaseBackup(
                $this->pdo,
                $this->databaseConfig,
                $this->profile->root,
            ))->restore($databaseBackup, $failedDir . '/database');
        }

        (new AssetPublisher($this->profile->root, $this->profile->publicDir))->publishAll();
        (new CacheClearer())->clearTemplateCaches();
        (new MaintenanceMode($this->profile->root))->disable();
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $state['phase'] = 'rolled_back';
        $state['error'] = $reason;
        $state['rolled_back_at'] = gmdate(\DateTimeInterface::ATOM);
        $this->stateStore->write($state);
        $this->log($state, 'Update rolled back: ' . $reason);
        return $state;
    }

    private function verifyLiveFiles(PackageManifest $manifest): void
    {
        foreach ($manifest->fileHashes as $logical => $expected) {
            $path = $this->livePath($logical);
            if (!is_file($path)) {
                throw new \RuntimeException("Post-update verification found a missing file: {$logical}");
            }
            $actual = 'sha256:' . hash_file('sha256', $path);
            if (!hash_equals(strtolower($expected), strtolower($actual))) {
                throw new \RuntimeException("Post-update verification failed for {$logical}.");
            }
        }
    }

    private function packagePath(PreparedPackage $prepared, string $logical): string
    {
        return str_starts_with($logical, 'public/')
            ? $prepared->publicDir . '/' . substr($logical, strlen('public/'))
            : $prepared->appDir . '/' . $logical;
    }

    private function livePath(string $logical): string
    {
        return str_starts_with($logical, 'public/')
            ? $this->profile->publicDir . '/' . substr($logical, strlen('public/'))
            : $this->profile->root . '/' . $logical;
    }

    private function ensureParent(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create directory {$dir}.");
        }
    }

    private function assertZipManaged(): void
    {
        if (!$this->profile->isZipManaged()) {
            throw new \RuntimeException('In-place updates are only available for zip-managed installations.');
        }
    }

    private function currentVersion(): string
    {
        return (string) \config('app.version', defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.0.0');
    }

    /**
     * @param array<string, mixed> $state
     */
    private function log(array $state, string $message): void
    {
        $path = $this->profile->root . '/storage/logs/upgrade.log';
        $this->ensureParent($path);
        $id = (string) ($state['id'] ?? 'unknown');
        @file_put_contents($path, '[' . gmdate('c') . "] [{$id}] {$message}\n", FILE_APPEND | LOCK_EX);
    }
}
