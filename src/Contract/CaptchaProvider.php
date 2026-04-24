<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface CaptchaProvider
{
    /**
     * Render the challenge markup for the given action.
     *
     * Implementations may return an empty string when no visible challenge is
     * needed. The returned HTML is inserted by Core at trusted integration
     * points only, so plugin authors must escape their own dynamic values.
     *
     * @param array<string, mixed> $context
     */
    public function render(string $action, array $context = []): string;

    /**
     * Verify the captcha response submitted with a request.
     *
     * @param array<string, mixed> $payload Usually $_POST.
     * @param array<string, mixed> $context Request metadata such as ip/email.
     */
    public function verify(array $payload, string $action, array $context = []): CaptchaResult;
}
