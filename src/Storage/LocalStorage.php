<?php
declare(strict_types=1);

namespace TypeDock\Storage;

use TypeDock\Contract\StorageDriver;

class LocalStorage implements StorageDriver
{
    private string $root;
    private string $url;

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        $this->root = rtrim((string) ($config['root'] ?? public_path('uploads')), '/');
        $this->url  = rtrim((string) ($config['url'] ?? config('app.url', '') . '/uploads'), '/');
    }

    public function put(string $path, string $contents): bool
    {
        $fullPath = $this->fullPath($path);
        $this->ensureDir(dirname($fullPath));
        return file_put_contents($fullPath, $contents) !== false;
    }

    public function putFile(string $path, string $localPath): bool
    {
        $fullPath = $this->fullPath($path);
        $this->ensureDir(dirname($fullPath));
        return copy($localPath, $fullPath);
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->fullPath($path);
        if (!file_exists($fullPath)) {
            return null;
        }
        $contents = file_get_contents($fullPath);
        return $contents !== false ? $contents : null;
    }

    public function exists(string $path): bool
    {
        return file_exists($this->fullPath($path));
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->fullPath($path);
        if (!file_exists($fullPath)) {
            return true;
        }
        return unlink($fullPath);
    }

    public function url(string $path): string
    {
        return $this->url . '/' . ltrim($path, '/');
    }

    public function listFiles(string $directory): array
    {
        $fullDir = $this->fullPath($directory);
        if (!is_dir($fullDir)) {
            return [];
        }
        $files = [];
        $items = scandir($fullDir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $fullItem = $fullDir . '/' . $item;
            if (is_file($fullItem)) {
                $files[] = ltrim($directory, '/') . '/' . $item;
            }
        }
        return $files;
    }

    private function fullPath(string $path): string
    {
        return $this->root . '/' . ltrim($path, '/');
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
