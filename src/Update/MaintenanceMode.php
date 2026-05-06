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

        file_put_contents($this->root . '/storage/.maintenance', json_encode($payload, JSON_PRETTY_PRINT));
        file_put_contents(
            $this->root . '/storage/.maintenance.html',
            "<!doctype html><meta charset=\"utf-8\"><title>Maintenance</title><h1>We'll be right back.</h1><p>TypeDock is being updated.</p>"
        );
    }

    public function disable(): void
    {
        @unlink($this->root . '/storage/.maintenance');
        @unlink($this->root . '/storage/.maintenance.html');
    }
}
