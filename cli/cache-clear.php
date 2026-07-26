<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

typedock_load_config(TYPEDOCK_ROOT);

function clearDir(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $count = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        if ($file->isFile()) {
            unlink($file->getPathname());
            $count++;
        }
    }
    return $count;
}

$count = clearDir(TYPEDOCK_ROOT . '/storage/cache/latte');

echo "Cache cleared ({$count} files deleted).\n";
