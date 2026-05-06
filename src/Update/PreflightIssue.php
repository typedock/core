<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class PreflightIssue
{
    public function __construct(
        public readonly string $severity,
        public readonly string $label,
        public readonly string $message,
    ) {}

    public static function ok(string $label, string $message): self
    {
        return new self('ok', $label, $message);
    }

    public static function warning(string $label, string $message): self
    {
        return new self('warning', $label, $message);
    }

    public static function error(string $label, string $message): self
    {
        return new self('error', $label, $message);
    }
}
