<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SourceContentful;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class SourceContentfulPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->registerExternalSourceAdapter(new ContentfulAdapter());
    }

    public function getName(): string
    {
        return 'Contentful Source Adapter';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return ['external-source-adapter:contentful'];
    }
}
