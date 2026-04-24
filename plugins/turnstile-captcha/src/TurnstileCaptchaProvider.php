<?php
declare(strict_types=1);

namespace TypeDock\Plugin\TurnstileCaptcha;

use TypeDock\Contract\CaptchaProvider;
use TypeDock\Contract\CaptchaResult;
use TypeDock\Core\PluginContext;

final class TurnstileCaptchaProvider implements CaptchaProvider
{
    private const VERIFY_ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private bool $scriptRendered = false;

    public function __construct(private readonly PluginContext $context) {}

    public function render(string $action, array $context = []): string
    {
        $siteKey = $this->siteKey();
        if ($siteKey === '' || $this->secretKey() === '') {
            return '';
        }

        $theme = $this->theme();
        $safeAction = preg_replace('/[^a-zA-Z0-9_-]/', '_', $action) ?: 'submit';

        $script = $this->scriptRendered
            ? ''
            : '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
        $this->scriptRendered = true;

        return sprintf(
            '<div class="cf-turnstile" data-sitekey="%s" data-action="%s" data-theme="%s"></div>%s',
            htmlspecialchars($siteKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($safeAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($theme, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $script
        );
    }

    public function verify(array $payload, string $action, array $context = []): CaptchaResult
    {
        $secret = $this->secretKey();
        if ($this->siteKey() === '' || $secret === '') {
            return CaptchaResult::pass();
        }

        $token = trim((string) ($payload['cf-turnstile-response'] ?? ''));
        if ($token === '') {
            return CaptchaResult::fail('Please complete the captcha challenge.');
        }

        try {
            $response = $this->context->http()->post(
                self::VERIFY_ENDPOINT,
                http_build_query([
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => (string) ($context['ip'] ?? ''),
                ]),
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                ['timeout' => 5, 'max_size' => 64 * 1024]
            );
        } catch (\Throwable $e) {
            $this->context->log()->warning('Turnstile verification request failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
            return CaptchaResult::fail('Captcha verification is temporarily unavailable.');
        }

        $body = $response->json();
        if ($response->ok() && is_array($body) && ($body['success'] ?? false) === true) {
            return CaptchaResult::pass();
        }

        $this->context->log()->warning('Turnstile verification failed', [
            'action' => $action,
            'status' => $response->status,
            'errors' => is_array($body) ? ($body['error-codes'] ?? []) : [],
        ]);

        return CaptchaResult::fail('Captcha verification failed.');
    }

    private function siteKey(): string
    {
        return trim((string) ($this->context->getSiteOption('turnstile.site_key') ?? ''));
    }

    private function secretKey(): string
    {
        return trim((string) ($this->context->getSiteOption('turnstile.secret_key') ?? ''));
    }

    private function theme(): string
    {
        $theme = (string) ($this->context->getSiteOption('turnstile.theme') ?? 'auto');
        return in_array($theme, ['auto', 'light', 'dark'], true) ? $theme : 'auto';
    }
}
