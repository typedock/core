<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class UpdatePackage
{
    public function prepare(string $zipPath, string $stageRoot, ReleaseMetadata $release): PreparedPackage
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('PHP ext-zip is required to prepare updates.');
        }
        if (file_exists($stageRoot)) {
            throw new \RuntimeException('The update staging directory already exists.');
        }
        if (!mkdir($stageRoot, 0775, true) && !is_dir($stageRoot)) {
            throw new \RuntimeException('Unable to create the update staging directory.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open the update package.');
        }

        try {
            $this->inspect($zip, $release->sizeBytes);
            if (!$zip->extractTo($stageRoot)) {
                throw new \RuntimeException('Unable to extract the update package.');
            }
        } finally {
            $zip->close();
        }

        return $this->loadPrepared($stageRoot, $release);
    }

    public function loadPrepared(string $stageRoot, ReleaseMetadata $release): PreparedPackage
    {
        $packageRoot = $stageRoot . '/typedock-shared';
        $appDir = $packageRoot . '/typedock';
        $publicDir = $packageRoot . '/public_html';
        if (!is_dir($appDir) || !is_dir($publicDir)) {
            throw new \RuntimeException('Update package does not contain the expected shared-hosting layout.');
        }

        $manifestPath = $appDir . '/typedock-package.json';
        if (!is_file($manifestPath)) {
            throw new \RuntimeException('Update package is missing typedock-package.json.');
        }
        $manifest = PackageManifest::fromFile($manifestPath);
        if ($manifest->version !== $release->version) {
            throw new \RuntimeException(
                "Package manifest version {$manifest->version} does not match release {$release->version}."
            );
        }
        if ($manifest->fileHashes === []) {
            throw new \RuntimeException('Update package manifest contains no file hashes.');
        }

        $this->verifyManifest($appDir, $publicDir, $manifest);
        return new PreparedPackage($stageRoot, $appDir, $publicDir, $manifest);
    }

    private function inspect(\ZipArchive $zip, int $compressedBytes): void
    {
        $uncompressed = 0;
        $limit = min(536_870_912, max(67_108_864, $compressedBytes * 30));

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
            if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
                throw new \RuntimeException('Update package contains an invalid archive path.');
            }
            if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name) === 1) {
                throw new \RuntimeException("Update package contains an unsafe archive path: {$name}");
            }
            if (!str_starts_with($name, 'typedock-shared/')) {
                throw new \RuntimeException('Update package contains files outside typedock-shared/.');
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $fileType = ($attributes >> 16) & 0xF000;
                if ($fileType === 0xA000) {
                    throw new \RuntimeException("Update package contains a symbolic link: {$name}");
                }
            }

            $uncompressed += (int) ($stat['size'] ?? 0);
            if ($uncompressed > $limit) {
                throw new \RuntimeException('Update package expands beyond the allowed size.');
            }
        }
    }

    private function verifyManifest(string $appDir, string $publicDir, PackageManifest $manifest): void
    {
        foreach ($manifest->managedPaths as $path) {
            $this->assertLogicalPath($path);
            if (!file_exists($this->physicalPath($appDir, $publicDir, $path))) {
                throw new \RuntimeException("Update package is missing managed path {$path}.");
            }
        }
        foreach (array_merge($manifest->bundledThemes, $manifest->bundledPlugins) as $slug) {
            if (preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
                throw new \RuntimeException("Update package contains an invalid extension slug: {$slug}");
            }
        }
        foreach ($manifest->fileHashes as $path => $expected) {
            $this->assertLogicalPath($path);
            $physical = $this->physicalPath($appDir, $publicDir, $path);
            if (!is_file($physical)) {
                throw new \RuntimeException("Update package manifest references a missing file: {$path}");
            }
            $actual = 'sha256:' . hash_file('sha256', $physical);
            if (!hash_equals(strtolower($expected), strtolower($actual))) {
                throw new \RuntimeException("Update package file hash mismatch: {$path}");
            }
        }
    }

    private function physicalPath(string $appDir, string $publicDir, string $logicalPath): string
    {
        if (str_starts_with($logicalPath, 'public/')) {
            return $publicDir . '/' . substr($logicalPath, strlen('public/'));
        }
        return $appDir . '/' . $logicalPath;
    }

    private function assertLogicalPath(string $path): void
    {
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1
        ) {
            throw new \RuntimeException("Update package manifest contains an unsafe path: {$path}");
        }
    }
}
