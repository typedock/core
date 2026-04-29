<?php
declare(strict_types=1);

namespace TypeDock\Core;

/**
 * Install a drop-in plugin from a zip archive uploaded through the admin UI.
 *
 * Plugins ship as a directory containing `plugin.json`; the upload may have
 * the plugin directly at the zip root, or wrapped in a single top-level
 * directory (which is what GitHub's "Download ZIP" button produces). We
 * collapse that wrapper so the plugin lands at `plugins/<slug>/` regardless
 * of how it was packaged.
 *
 * The installer enforces the structural rules from CLAUDE.md / doc28:
 *   - slug matches `^[a-z][a-z0-9_-]{1,63}$`
 *   - manifest slug equals the directory we extract into
 *   - no entry escapes the plugin dir (no `..`, no absolute paths)
 *   - no `.php` under a `public/` subdir (would be web-reachable directly)
 *
 * Plugin enablement is left to the admin toggle — install never auto-enables,
 * matching how drop-ins discovered on disk start out disabled until someone
 * flips the switch in `/admin/settings/modules`.
 */
final class PluginInstaller
{
    private const SLUG_REGEX = '/^[a-z][a-z0-9_-]{1,63}$/';

    /** @var array<string> Subset of zip entries dangerous to extract */
    private const DENYLIST_PHP_UNDER_PUBLIC = '#(^|/)public/.*\.php$#i';

    public function __construct(
        private readonly string $pluginsDir,
    ) {}

    /**
     * @param string $zipPath Path to an uploaded zip on local disk
     * @param bool   $overwrite True to replace an existing plugin directory
     * @return array{slug: string, replaced: bool}
     * @throws \RuntimeException on validation failure
     */
    public function install(string $zipPath, bool $overwrite = false): array
    {
        if (!is_file($zipPath)) {
            throw new \RuntimeException('Upload not found.');
        }

        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new \RuntimeException('Could not open zip archive (corrupt?).');
        }

        try {
            $entries  = $this->collectEntries($zip);
            $prefix   = $this->commonRootPrefix($entries);
            $manifest = $this->readManifestFromZip($zip, $prefix);
            $slug     = $this->validateManifest($manifest);
            $this->screenEntries($entries, $prefix);

            $target  = rtrim($this->pluginsDir, '/') . '/' . $slug;
            $existed = is_dir($target);
            if ($existed && !$overwrite) {
                throw new \RuntimeException(
                    "Plugin '{$slug}' already exists. Tick 'Overwrite' to replace it."
                );
            }

            $this->extract($zip, $entries, $prefix, $target);

            return ['slug' => $slug, 'replaced' => $existed];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function collectEntries(\ZipArchive $zip): array
    {
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $entries[] = $name;
        }
        if ($entries === []) {
            throw new \RuntimeException('Zip archive is empty.');
        }
        return $entries;
    }

    /**
     * Collapse a single wrapping directory if all entries share one. Returns
     * the prefix (with trailing slash) to strip, or '' when the manifest is
     * already at the zip root.
     *
     * @param array<int, string> $entries
     */
    private function commonRootPrefix(array $entries): string
    {
        $first = $entries[0];
        $slashAt = strpos($first, '/');
        if ($slashAt === false) {
            return '';
        }
        $candidate = substr($first, 0, $slashAt + 1);
        foreach ($entries as $name) {
            if (!str_starts_with($name, $candidate)) {
                return '';
            }
        }
        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifestFromZip(\ZipArchive $zip, string $prefix): array
    {
        $entry = $prefix . 'plugin.json';
        $raw   = $zip->getFromName($entry);
        if ($raw === false) {
            throw new \RuntimeException("Archive does not contain '{$entry}'.");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('plugin.json is not valid JSON.');
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function validateManifest(array $manifest): string
    {
        $slug = (string) ($manifest['slug'] ?? '');
        if (preg_match(self::SLUG_REGEX, $slug) !== 1) {
            throw new \RuntimeException(
                "Manifest slug '{$slug}' is invalid. Allowed: lowercase letters, "
                . 'digits, hyphens and underscores; must start with a letter.'
            );
        }
        if (trim((string) ($manifest['main_class'] ?? '')) === '') {
            throw new \RuntimeException('Manifest is missing main_class.');
        }
        return $slug;
    }

    /**
     * Reject entries that escape the prefix or land under `public/*.php`.
     *
     * @param array<int, string> $entries
     */
    private function screenEntries(array $entries, string $prefix): void
    {
        foreach ($entries as $name) {
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                throw new \RuntimeException("Entry escapes plugin root: {$name}");
            }
            $rel = $prefix === '' ? $name : substr($name, strlen($prefix));
            if ($rel === '') {
                continue;
            }
            // Reject path traversal and absolute paths.
            if (str_contains($rel, '..') || str_starts_with($rel, '/') || preg_match('/^[a-zA-Z]:\\\\/', $rel)) {
                throw new \RuntimeException("Unsafe path in archive: {$name}");
            }
            if (preg_match(self::DENYLIST_PHP_UNDER_PUBLIC, $rel) === 1) {
                throw new \RuntimeException(
                    "Plugin contains PHP under public/ ({$rel}). Per the plugin "
                    . 'contract, no .php may be web-reachable from public/.'
                );
            }
        }
    }

    /**
     * @param array<int, string> $entries
     */
    private function extract(\ZipArchive $zip, array $entries, string $prefix, string $target): void
    {
        $stagingDir = $target . '.installing-' . bin2hex(random_bytes(4));
        if (!mkdir($stagingDir, 0775, true)) {
            throw new \RuntimeException("Cannot create staging dir: {$stagingDir}");
        }

        try {
            foreach ($entries as $name) {
                $rel = $prefix === '' ? $name : substr($name, strlen($prefix));
                if ($rel === '') {
                    continue;
                }
                $dest = $stagingDir . '/' . $rel;

                if (str_ends_with($rel, '/')) {
                    if (!is_dir($dest) && !mkdir($dest, 0775, true)) {
                        throw new \RuntimeException("Cannot mkdir: {$dest}");
                    }
                    continue;
                }

                $parent = dirname($dest);
                if (!is_dir($parent) && !mkdir($parent, 0775, true)) {
                    throw new \RuntimeException("Cannot mkdir: {$parent}");
                }

                $contents = $zip->getFromName($name);
                if ($contents === false) {
                    throw new \RuntimeException("Cannot read entry: {$name}");
                }
                if (file_put_contents($dest, $contents) === false) {
                    throw new \RuntimeException("Cannot write: {$dest}");
                }
            }

            // Atomically swap staging into place.
            if (is_dir($target)) {
                $trash = $target . '.removing-' . bin2hex(random_bytes(4));
                if (!rename($target, $trash)) {
                    throw new \RuntimeException("Cannot move existing plugin out of the way: {$target}");
                }
                $this->rmdirRecursive($trash);
            }
            if (!rename($stagingDir, $target)) {
                throw new \RuntimeException("Cannot move staged plugin into place: {$target}");
            }
        } catch (\Throwable $e) {
            $this->rmdirRecursive($stagingDir);
            throw $e;
        }
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
