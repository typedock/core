<?php
declare(strict_types=1);

namespace TypeDock\Seo;

final class BreadcrumbItem
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly bool $isCurrent,
    ) {}
}
