<?php
declare(strict_types=1);

namespace TypeDock\Core;

final class CacheClearer
{
    /** Clear compiled Latte templates. Returns the number of files deleted. */
    public function clearTemplateCaches(): int
    {
        return $this->clearDirectory((string) config('cache.latte_dir', TYPEDOCK_ROOT . '/storage/cache/latte'));
    }

    private function clearDirectory(string $dir): int
    {
        if ($dir === '' || !is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isFile() || $item->isLink()) {
                if (!@unlink($path)) {
                    throw new \RuntimeException('Failed to delete cache file: ' . $path);
                }
                $count++;
                continue;
            }

            if ($item->isDir() && !@rmdir($path)) {
                throw new \RuntimeException('Failed to delete cache directory: ' . $path);
            }
        }

        return $count;
    }
}
