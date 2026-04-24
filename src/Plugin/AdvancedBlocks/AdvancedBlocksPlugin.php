<?php
declare(strict_types=1);

namespace TypeDock\Plugin\AdvancedBlocks;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

class AdvancedBlocksPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        // TODO: Register callout, balloon, table blocks
    }

    public function getName(): string
    {
        return 'AdvancedBlocks';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return [];
    }
}
