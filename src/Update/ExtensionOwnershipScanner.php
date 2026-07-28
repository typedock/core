<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class ExtensionOwnershipScanner
{
    public function __construct(
        private readonly string $root,
        private readonly PackageManifest $currentManifest,
    ) {}

    /**
     * @return list<array{type:string,slug:string,status:string,message:string}>
     */
    public function scan(?PackageManifest $targetManifest = null): array
    {
        $targetManifest ??= $this->currentManifest;

        $rows = [];
        foreach ($this->scanType('theme', 'themes', $this->currentManifest->bundledThemes, $targetManifest->bundledThemes) as $row) {
            $rows[] = $row;
        }
        foreach ($this->scanType('plugin', 'plugins', $this->currentManifest->bundledPlugins, $targetManifest->bundledPlugins) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<string> $currentBundled
     * @param list<string> $targetBundled
     * @return list<array{type:string,slug:string,status:string,message:string}>
     */
    private function scanType(string $type, string $dirName, array $currentBundled, array $targetBundled): array
    {
        $base = $this->root . '/' . $dirName;
        $rows = [];

        $local = $this->localSlugs($base);
        foreach ($local as $slug) {
            $isCurrentBundled = in_array($slug, $currentBundled, true);
            $isTargetBundled = in_array($slug, $targetBundled, true);

            if (!$isCurrentBundled && $isTargetBundled) {
                $rows[] = [
                    'type' => $type,
                    'slug' => $slug,
                    'status' => 'collision',
                    'message' => "A local {$type} uses a slug that the update wants to manage.",
                ];
                continue;
            }

            if (!$isCurrentBundled) {
                $rows[] = [
                    'type' => $type,
                    'slug' => $slug,
                    'status' => 'user-owned',
                    'message' => 'User-owned; core updater will not modify it.',
                ];
                continue;
            }

            $dir = $dirName . '/' . $slug;
            if (!$isTargetBundled) {
                $rows[] = [
                    'type' => $type,
                    'slug' => $slug,
                    'status' => 'removed-bundled',
                    'message' => 'No longer bundled by TypeDock; preserved as an installed extension.',
                ];
                continue;
            }

            $hashes = $this->currentManifest->hashesUnder($dir);
            if ($hashes === []) {
                $rows[] = [
                    'type' => $type,
                    'slug' => $slug,
                    'status' => 'managed-untracked',
                    'message' => 'Bundled by TypeDock, but this install has no package hashes to prove local changes.',
                ];
                continue;
            }

            $diff = $this->diffDirectory($dir, $hashes);
            $rows[] = [
                'type' => $type,
                'slug' => $slug,
                'status' => $diff === [] ? 'clean' : 'modified',
                'message' => $diff === []
                    ? 'Bundled by TypeDock and unchanged.'
                    : 'Bundled by TypeDock but modified locally; updater must back it up before overwriting.',
            ];
        }

        foreach ($targetBundled as $slug) {
            if (!in_array($slug, $local, true) && !in_array($slug, $currentBundled, true)) {
                $rows[] = [
                    'type' => $type,
                    'slug' => $slug,
                    'status' => 'new-bundled',
                    'message' => 'New bundled extension from the update.',
                ];
            }
        }

        usort($rows, static fn(array $a, array $b): int => [$a['type'], $a['slug']] <=> [$b['type'], $b['slug']]);
        return $rows;
    }

    /**
     * @return list<string>
     */
    private function localSlugs(string $base): array
    {
        if (!is_dir($base)) {
            return [];
        }

        $slugs = [];
        foreach (scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($base . '/' . $entry) && preg_match('/^[A-Za-z0-9_-]+$/', $entry) === 1) {
                $slugs[] = $entry;
            }
        }
        sort($slugs);
        return $slugs;
    }

    /**
     * @param array<string, string> $expected
     * @return list<string>
     */
    private function diffDirectory(string $relativeDir, array $expected): array
    {
        $actual = $this->hashDirectory($relativeDir);
        $diff = [];

        foreach ($expected as $path => $hash) {
            if (!isset($actual[$path]) || $actual[$path] !== $hash) {
                $diff[] = $path;
            }
        }

        foreach ($actual as $path => $_hash) {
            if (!isset($expected[$path])) {
                $diff[] = $path;
            }
        }

        return $diff;
    }

    /**
     * @return array<string, string>
     */
    private function hashDirectory(string $relativeDir): array
    {
        $base = $this->root . '/' . trim($relativeDir, '/');
        if (!is_dir($base)) {
            return [];
        }

        $hashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo || !$item->isFile()) {
                continue;
            }
            $path = $item->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($this->root) + 1));
            $hashes[$relative] = 'sha256:' . hash_file('sha256', $path);
        }

        ksort($hashes);
        return $hashes;
    }
}
