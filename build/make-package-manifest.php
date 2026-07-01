<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$managedPaths = [
    'vendor',
    'src',
    'migrations',
    'cli',
    'admin',
    'public/admin/dist',
    'composer.json',
    'composer.lock',
    'LICENSE',
    'README.md',
];
$bundledThemes = ['default', 'kinari'];
$bundledPlugins = ['form', 'redirect', 'social', 'image-optimizer', 'turnstile-captcha', 'advanced-blocks', 'backup', 'simple-ai-writing', 'source-contentful', 'source-github', 'source-github-docs', 'cloud-storage'];

$fileHashes = [];
foreach (array_merge($managedPaths, array_map(fn(string $s): string => 'themes/' . $s, $bundledThemes), array_map(fn(string $s): string => 'plugins/' . $s, $bundledPlugins)) as $path) {
    collectHashes($root, $path, $fileHashes);
}
ksort($fileHashes);

$manifest = [
    'schema_version' => 1,
    'version' => getenv('VERSION') ?: '1.0.0-rc5',
    'managed_paths' => $managedPaths,
    'bundled_themes' => $bundledThemes,
    'bundled_plugins' => $bundledPlugins,
    'file_hashes' => $fileHashes,
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

/**
 * @param array<string, string> $hashes
 */
function collectHashes(string $root, string $relative, array &$hashes): void
{
    $full = $root . '/' . trim($relative, '/');
    if (is_file($full)) {
        $hashes[trim($relative, '/')] = 'sha256:' . hash_file('sha256', $full);
        return;
    }
    if (!is_dir($full)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }
        $path = $item->getPathname();
        $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $hashes[$rel] = 'sha256:' . hash_file('sha256', $path);
    }
}
