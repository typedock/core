<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface SearchEngine
{
    /**
     * @param  array<string, mixed> $options
     * @return array{items: array<mixed>, total: int}
     */
    public function search(string $query, array $options = []): array;
}
