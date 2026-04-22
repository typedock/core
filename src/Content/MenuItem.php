<?php
declare(strict_types=1);

namespace TypeDock\Content;

final class MenuItem
{
    /**
     * @param array<MenuItem> $children
     */
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly string $targetType,
        public readonly ?string $cssClass,
        public readonly array $children,
    ) {}
}
