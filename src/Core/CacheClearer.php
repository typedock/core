<?php
declare(strict_types=1);

namespace TypeDock\Core;

final class CacheClearer
{
    /**
     * Clear compiled templates and static HTML cache.
     *
     * @return array{latte:int, html:int, total:int}
     */
    public function clearTemplateCaches(): array
    {
        $latte = $this->clearDirectory((string) config('cache.latte_dir', TYPEDOCK_ROOT . '/storage/cache/latte'));
        $html  = $this->clearDirectory((string) config('cache.html_dir', TYPEDOCK_ROOT . '/storage/cache/html'));

        return [
            'latte' => $latte,
            'html'  => $html,
            'total' => $latte + $html,
        ];
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
