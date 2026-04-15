<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Social;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

class SocialPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        // TODO: Register SNS share/follow button components
    }

    public function getName(): string
    {
        return 'Social';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }
}
