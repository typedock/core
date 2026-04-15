<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

class CacheMiddleware
{
    public function handle(): void
    {
        if (!config('cache.static_html', false)) {
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }

        // Don't cache admin or API routes
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        if (str_starts_with($uri, '/admin') || str_starts_with($uri, '/api')) {
            return;
        }

        $cacheFile = $this->getCacheFilePath($uri);
        if (file_exists($cacheFile)) {
            $ttl      = (int) config('cache.ttl', 3600);
            $mtime    = filemtime($cacheFile);
            if ($mtime !== false && (time() - $mtime) < $ttl) {
                header('X-Cache: HIT');
                readfile($cacheFile);
                exit;
            }
        }
    }

    public function store(string $uri, string $html): void
    {
        if (!config('cache.static_html', false)) {
            return;
        }

        $cacheFile = $this->getCacheFilePath($uri);
        $dir       = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($cacheFile, $html);
    }

    public function clear(string $uri = ''): void
    {
        $htmlDir = config('cache.html_dir', TYPEDOCK_ROOT . '/storage/cache/html');
        if ($uri === '') {
            $this->clearDirectory($htmlDir);
        } else {
            $file = $this->getCacheFilePath($uri);
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function getCacheFilePath(string $uri): string
    {
        $htmlDir = config('cache.html_dir', TYPEDOCK_ROOT . '/storage/cache/html');
        $hash    = md5($uri);
        return $htmlDir . '/' . substr($hash, 0, 2) . '/' . $hash . '.html';
    }

    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
            }
        }
    }
}
