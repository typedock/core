<?php
declare(strict_types=1);

namespace TypeDock\Security;

use TypeDock\Contract\CaptchaProvider;
use TypeDock\Contract\CaptchaResult;

final class NullCaptchaProvider implements CaptchaProvider
{
    public function render(string $action, array $context = []): string
    {
        return '';
    }

    public function verify(array $payload, string $action, array $context = []): CaptchaResult
    {
        return CaptchaResult::pass();
    }
}
