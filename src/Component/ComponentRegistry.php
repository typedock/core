<?php
declare(strict_types=1);

namespace TypeDock\Component;

class ComponentRegistry
{
    /** @var array<string, ComponentDefinition> */
    private array $components = [];

    public function register(ComponentDefinition $def): void
    {
        $this->components[$def->type] = $def;
    }

    public function get(string $type): ?ComponentDefinition
    {
        return $this->components[$type] ?? null;
    }

    /**
     * @return array<string, ComponentDefinition>
     */
    public function list(): array
    {
        return $this->components;
    }

    public function has(string $type): bool
    {
        return isset($this->components[$type]);
    }
}
