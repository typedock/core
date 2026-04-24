<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Util;

/**
 * File-backed key/value cache with TTL. Plugin-scoped — each plugin gets its
 * own subdirectory under storage/cache/plugins/<slug>/ so cache clears can
 * be targeted and one plugin can't poison another's keys.
 *
 * Design choices:
 *   - Keys are hashed (sha1) to sidestep filename legality and length limits
 *     across MySQL / Windows / shared hosts
 *   - Payload is serialize()'d PHP (includes scalars, arrays, objects)
 *   - First 10 bytes of the file encode the expiry as a decimal unix ts so
 *     get() can skip unserialize on a miss
 */
class FileCache
{
    public function __construct(private readonly string $baseDir)
    {
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0775, true);
        }
    }

    public function get(string $key): mixed
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            $header = (string) fread($fh, 11);
            $expiry = (int) trim(substr($header, 0, 10));
            if ($expiry !== 0 && $expiry < time()) {
                @unlink($path);
                return null;
            }
            $payload = stream_get_contents($fh);
            if ($payload === false || $payload === '') {
                return null;
            }
            $value = @unserialize($payload, ['allowed_classes' => true]);
            return $value === false && $payload !== 'b:0;' ? null : $value;
        } finally {
            fclose($fh);
        }
    }

    public function set(string $key, mixed $value, int $ttlSeconds = 3600): void
    {
        $path   = $this->path($key);
        $expiry = $ttlSeconds > 0 ? (time() + $ttlSeconds) : 0;
        $header = str_pad((string) $expiry, 10, '0', STR_PAD_LEFT) . "\n";
        @file_put_contents($path, $header . serialize($value), LOCK_EX);
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Memoise: return the cached value, or run $loader, cache its result, and
     * return it. Simplest way for plugins to wrap expensive SaaS calls.
     */
    public function remember(string $key, int $ttlSeconds, callable $loader): mixed
    {
        $existing = $this->get($key);
        if ($existing !== null) {
            return $existing;
        }
        $value = $loader();
        $this->set($key, $value, $ttlSeconds);
        return $value;
    }

    public function clear(): void
    {
        foreach (glob($this->baseDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function path(string $key): string
    {
        return $this->baseDir . '/' . sha1($key) . '.cache';
    }
}
