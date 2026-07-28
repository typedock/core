<?php
declare(strict_types=1);

$options = getopt('', ['root:', 'public-root:', 'version:', 'list-bundled-plugins']);

$root = isset($options['root'])
    ? rtrim((string) $options['root'], DIRECTORY_SEPARATOR)
    : dirname(__DIR__);
$publicRoot = isset($options['public-root'])
    ? rtrim((string) $options['public-root'], DIRECTORY_SEPARATOR)
    : $root . '/public';

$managedPaths = [
    'vendor',
    'src',
    'config',
    'migrations',
    'cli',
    'admin',
    'docs',
    'public/index.php',
    'public/install.php',
    'public/.htaccess',
    'public/admin/assets',
    'public/admin/dist',
    'composer.json',
    'composer.lock',
    'config.php.example',
    '.htaccess',
    'LICENSE',
    'NOTICE',
    'README.md',
    'SECURITY.md',
];
$bundledThemes = ['default', 'kinari'];
$bundledPlugins = [
    'advanced-blocks',
    'backup',
    'form',
    'image-optimizer',
    'import-wordpress',
    'redirect',
    'simple-ai-writing',
    'social',
    'source-contentful',
    'source-github',
    'source-github-docs',
    'turnstile-captcha',
];

if (array_key_exists('list-bundled-plugins', $options)) {
    echo implode("\n", $bundledPlugins) . "\n";
    exit(0);
}

$fileHashes = [];
foreach (array_merge($managedPaths, array_map(fn(string $s): string => 'themes/' . $s, $bundledThemes), array_map(fn(string $s): string => 'plugins/' . $s, $bundledPlugins)) as $path) {
    collectHashes($root, $publicRoot, $path, $fileHashes);
}
ksort($fileHashes);

$manifest = [
    'schema_version' => 1,
    'version' => isset($options['version']) ? (string) $options['version'] : (getenv('VERSION') ?: '1.0.0-rc6'),
    'managed_paths' => $managedPaths,
    'bundled_themes' => $bundledThemes,
    'bundled_plugins' => $bundledPlugins,
    'file_hashes' => $fileHashes,
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

/**
 * @param array<string, string> $hashes
 */
function collectHashes(string $root, string $publicRoot, string $relative, array &$hashes): void
{
    $relative = trim($relative, '/');
    $isPublic = $relative === 'public' || str_starts_with($relative, 'public/');
    $physicalRoot = $isPublic ? $publicRoot : $root;
    $physicalRelative = $isPublic ? ltrim(substr($relative, strlen('public')), '/') : $relative;
    $full = $physicalRoot . ($physicalRelative !== '' ? '/' . $physicalRelative : '');
    if (is_file($full)) {
        $hashes[$relative] = 'sha256:' . hash_file('sha256', $full);
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
        $physicalRel = str_replace('\\', '/', substr($path, strlen($physicalRoot) + 1));
        $rel = $isPublic ? 'public/' . $physicalRel : $physicalRel;
        $hashes[$rel] = 'sha256:' . hash_file('sha256', $path);
    }
}
