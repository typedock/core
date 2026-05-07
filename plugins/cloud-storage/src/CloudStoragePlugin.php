<?php
declare(strict_types=1);

namespace TypeDock\Plugin\CloudStorage;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class CloudStoragePlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $settings = CloudStorageSettings::load($context);
        if (CloudStorageSettings::canProvide($settings)) {
            $context->provideSingle('storage', new S3CompatibleStorage($settings));
        }

        $controller = new CloudStorageSettingsController($context);
        $context->registerAdminRoute('GET', '', [$controller, 'edit']);
        $context->registerAdminRoute('POST', '', [$controller, 'update']);
        $context->addAdminMenuItem('Cloud Storage', '');
    }

    public function getName(): string
    {
        return 'Cloud Storage';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return ['storage'];
    }
}
