<?php
declare(strict_types=1);

namespace TypeDock\Plugin\TurnstileCaptcha;

use TypeDock\Core\PluginContext;

final class TurnstileSettingsController
{
    public function __construct(private readonly PluginContext $context) {}

    public function edit(): void
    {
        $this->context->view('templates/admin/settings.latte', [
            'settings' => $this->settings(),
            'flash'    => $this->context->getFlash('success'),
        ]);
    }

    public function update(): void
    {
        $this->context->setSiteOption('turnstile.site_key', trim((string) ($_POST['site_key'] ?? '')));
        $this->context->setSiteOption('turnstile.secret_key', trim((string) ($_POST['secret_key'] ?? '')));

        $theme = (string) ($_POST['theme'] ?? 'auto');
        if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
            $theme = 'auto';
        }
        $this->context->setSiteOption('turnstile.theme', $theme);

        $this->context->redirect('', 'Captcha settings saved.');
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        return [
            'site_key'   => trim((string) ($this->context->getSiteOption('turnstile.site_key') ?? '')),
            'secret_key' => trim((string) ($this->context->getSiteOption('turnstile.secret_key') ?? '')),
            'theme'      => trim((string) ($this->context->getSiteOption('turnstile.theme') ?? 'auto')),
        ];
    }
}
