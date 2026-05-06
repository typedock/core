<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class InstallationProfile
{
    public function __construct(
        public readonly string $root,
        public readonly string $publicDir,
        public readonly string $mode,
        public readonly bool $selfUpdateEnabled,
    ) {}

    public static function fromRuntime(?string $root = null, ?string $publicDir = null): self
    {
        $root = rtrim($root ?? (defined('TYPEDOCK_ROOT') ? TYPEDOCK_ROOT : dirname(__DIR__, 2)), '/');
        $publicDir = rtrim($publicDir ?? (defined('TYPEDOCK_PUBLIC_DIR') ? TYPEDOCK_PUBLIC_DIR : $root . '/public'), '/');

        $configured = (string) \config('update.installation_mode', 'auto');
        $mode = self::resolveMode($configured, $root);

        return new self(
            root: $root,
            publicDir: $publicDir,
            mode: $mode,
            selfUpdateEnabled: (bool) \config('update.self_update_enabled', true),
        );
    }

    public function isZipManaged(): bool
    {
        return $this->mode === 'zip' && $this->selfUpdateEnabled;
    }

    public function isSplitPublic(): bool
    {
        return $this->normalise($this->publicDir) !== $this->normalise($this->root . '/public');
    }

    private static function resolveMode(string $configured, string $root): string
    {
        $configured = strtolower(trim($configured));
        if (in_array($configured, ['zip', 'container', 'source'], true)) {
            return $configured;
        }

        if (is_file('/.dockerenv')) {
            return 'container';
        }
        if (is_dir($root . '/.git')) {
            return 'source';
        }
        return 'zip';
    }

    private function normalise(string $path): string
    {
        $real = realpath($path);
        return rtrim($real !== false ? $real : $path, '/');
    }
}
