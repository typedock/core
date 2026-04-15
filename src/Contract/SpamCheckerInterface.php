<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface SpamCheckerInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function isSpam(array $data): bool;
}
