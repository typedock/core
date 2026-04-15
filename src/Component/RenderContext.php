<?php
declare(strict_types=1);

namespace TypeDock\Component;

class RenderContext
{
    public function __construct(
        public readonly string $locale = 'en',
        /** @var array<string, mixed>|null */
        public readonly ?array $page = null,
        public readonly string $currentUrl = '/'
    ) {}
}
