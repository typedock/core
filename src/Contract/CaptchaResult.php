<?php
declare(strict_types=1);

namespace TypeDock\Contract;

final class CaptchaResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?string $error = null
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function fail(string $error = 'Captcha verification failed.'): self
    {
        return new self(false, $error);
    }
}
