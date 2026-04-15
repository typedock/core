<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

$envFile = TYPEDOCK_ROOT . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

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

$latteDir = TYPEDOCK_ROOT . '/storage/cache/latte';
$htmlDir  = TYPEDOCK_ROOT . '/storage/cache/html';

$count  = clearDir($latteDir);
$count += clearDir($htmlDir);

echo "Cache cleared ({$count} files deleted).\n";
