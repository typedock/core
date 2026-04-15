<?php
declare(strict_types=1);

namespace TypeDock\Component;

class ComponentDefinition
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $icon = '',
        /** @var array<array<string, mixed>> */
        public readonly array $params = [],
        /** @var array<string> */
        public readonly array $placeable = ['slot', 'block'],
        public readonly string $template = '',
        public readonly ?string $dataProvider = null,
        public readonly ?string $module = null,
        /** @var array<string, mixed> */
        public readonly array $cache = []
    ) {}
}
