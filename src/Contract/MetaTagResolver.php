<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface MetaTagResolver
{
    /**
     * @return array<string, string>
     */
    public function resolve(string $targetType, string $targetId): array;
}
