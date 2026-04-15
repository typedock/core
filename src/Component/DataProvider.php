<?php
declare(strict_types=1);

namespace TypeDock\Component;

interface DataProvider
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function resolve(array $params, RenderContext $context): array;
}
