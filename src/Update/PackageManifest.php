<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class PackageManifest
{
    /**
     * @param list<string> $managedPaths
     * @param list<string> $bundledThemes
     * @param list<string> $bundledPlugins
     * @param array<string, string> $fileHashes
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $version,
        public readonly array $managedPaths,
        public readonly array $bundledThemes,
        public readonly array $bundledPlugins,
        public readonly array $fileHashes,
    ) {}

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return self::fallback();
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid package manifest JSON: ' . $path);
        }

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            schemaVersion: max(1, (int) ($data['schema_version'] ?? 1)),
            version: (string) ($data['version'] ?? '0.0.0'),
            managedPaths: self::stringList($data['managed_paths'] ?? []),
            bundledThemes: self::stringList($data['bundled_themes'] ?? []),
            bundledPlugins: self::stringList($data['bundled_plugins'] ?? []),
            fileHashes: self::hashMap($data['file_hashes'] ?? []),
        );
    }

    public static function fallback(): self
    {
        return new self(
            schemaVersion: 1,
            version: defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.8.0',
            managedPaths: ['vendor', 'src', 'migrations', 'cli', 'admin', 'public/admin/dist', 'composer.json', 'composer.lock'],
            bundledThemes: ['default', 'kinari'],
            bundledPlugins: ['form', 'redirect', 'social', 'image-optimizer', 'turnstile-captcha', 'advanced-blocks', 'backup', 'source-contentful', 'source-github', 'cloud-storage'],
            fileHashes: [],
        );
    }

    public function ownsTheme(string $slug): bool
    {
        return in_array($slug, $this->bundledThemes, true);
    }

    public function ownsPlugin(string $slug): bool
    {
        return in_array($slug, $this->bundledPlugins, true);
    }

    /**
     * @return array<string, string>
     */
    public function hashesUnder(string $relativeDir): array
    {
        $prefix = trim($relativeDir, '/') . '/';
        $out = [];
        foreach ($this->fileHashes as $path => $hash) {
            if (str_starts_with($path, $prefix)) {
                $out[$path] = $hash;
            }
        }
        return $out;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = trim($item, '/');
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private static function hashMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $path => $hash) {
            if (is_string($path) && is_string($hash) && $path !== '' && $hash !== '') {
                $out[trim($path, '/')] = $hash;
            }
        }
        return $out;
    }
}
