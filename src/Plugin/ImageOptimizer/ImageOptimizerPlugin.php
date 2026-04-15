<?php
declare(strict_types=1);

namespace TypeDock\Plugin\ImageOptimizer;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

class ImageOptimizerPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        // TODO: Register MediaProcessor filter for WebP conversion
    }

    public function getName(): string
    {
        return 'ImageOptimizer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }
}
