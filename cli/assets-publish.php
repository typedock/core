<?php
declare(strict_types=1);

/**
 * Publish theme and plugin static assets into public/ for direct web server delivery.
 *
 * Usage:
 *   php cli/assets-publish.php            Publish all theme/plugin assets
 *   php cli/assets-publish.php --clean    Remove all published assets
 */

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

$publisher = new TypeDock\Core\AssetPublisher(TYPEDOCK_ROOT);

if (in_array('--clean', $argv ?? [], true)) {
    // Remove published asset directories.
    $dirs = ['themes', 'plugins'];
    foreach ($dirs as $dir) {
        $full = public_path($dir);
        if (is_dir($full)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($full);
            echo "  Removed public/{$dir}/\n";
        }
    }
    echo "Done.\n";
    exit(0);
}

echo "Publishing assets...\n";
$results = $publisher->publishAll();

if (empty($results)) {
    echo "  No theme/plugin assets found to publish.\n";
} else {
    foreach ($results as $source => $dest) {
        // Show relative paths for readability.
        $relSource = str_replace(TYPEDOCK_ROOT . '/', '', $source);
        $relDest   = str_replace(TYPEDOCK_ROOT . '/', '', $dest);
        echo "  {$relSource} -> {$relDest}\n";
    }
}

echo "Done.\n";
