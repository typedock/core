<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Form;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

class FormPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        // TODO: Register form component and routes
    }

    public function getName(): string
    {
        return 'Form';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }
}
