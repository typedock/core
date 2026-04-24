<?php
declare(strict_types=1);

namespace TypeDock\Plugin\TurnstileCaptcha;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class TurnstileCaptchaPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->provideSingle('captcha', new TurnstileCaptchaProvider($context));

        $controller = new TurnstileSettingsController($context);
        $context->registerAdminRoute('GET', '', [$controller, 'edit']);
        $context->registerAdminRoute('POST', '', [$controller, 'update']);
        $context->addAdminMenuItem('Captcha', '');
    }

    public function getName(): string
    {
        return 'Turnstile Captcha';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return ['captcha'];
    }
}
