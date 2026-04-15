<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface ModuleInterface
{
    public function register(): void;
}
