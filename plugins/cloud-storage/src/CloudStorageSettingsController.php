<?php
declare(strict_types=1);

namespace TypeDock\Plugin\CloudStorage;

use TypeDock\Core\PluginContext;

final class CloudStorageSettingsController
{
    public function __construct(private readonly PluginContext $context) {}

    public function edit(): void
    {
        $settings = CloudStorageSettings::load($this->context);
        $this->context->view('templates/admin/settings.latte', [
            'settings'    => $settings,
            'diagnostics' => CloudStorageSettings::diagnostics($settings),
            'flash'       => $this->context->getFlash('success'),
            'error'       => $this->context->getFlash('error'),
        ]);
    }

    public function update(): void
    {
        try {
            CloudStorageSettings::save($this->context, $_POST);
            $this->context->redirect('', 'Cloud Storage settings saved.');
        } catch (\Throwable $e) {
            $this->context->redirect('', $e->getMessage(), 'error');
        }
    }
}
