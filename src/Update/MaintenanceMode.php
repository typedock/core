<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class MaintenanceMode
{
    public function __construct(private readonly string $root) {}

    public function enable(string $reason, ?string $token = null): void
    {
        $payload = [
            'since' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'reason' => $reason,
            'token' => $token ?? bin2hex(random_bytes(16)),
        ];

        $storage = $this->root . '/storage';
        if (!is_dir($storage) || !is_writable($storage)) {
            throw new \RuntimeException('Storage directory is not writable; maintenance mode cannot be enabled.');
        }
        $htmlPath = $storage . '/.maintenance.html';
        if (file_put_contents(
            $htmlPath,
            "<!doctype html><meta charset=\"utf-8\"><title>Maintenance</title><h1>We'll be right back.</h1><p>TypeDock is being updated.</p>"
        ) === false) {
            throw new \RuntimeException('Unable to write the maintenance page.');
        }
        $tmp = $storage . '/.maintenance.tmp';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $storage . '/.maintenance')) {
            @unlink($tmp);
            @unlink($htmlPath);
            throw new \RuntimeException('Unable to enable maintenance mode.');
        }
    }

    public function disable(): void
    {
        @unlink($this->root . '/storage/.maintenance');
        @unlink($this->root . '/storage/.maintenance.html');
        if (is_file($this->root . '/storage/.maintenance')) {
            throw new \RuntimeException('Unable to disable maintenance mode.');
        }
    }
}
