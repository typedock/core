<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface PluginInterface
{
    public function register(\TypeDock\Core\PluginContext $context): void;
    public function getName(): string;
    public function getVersion(): string;
}
