<?php
declare(strict_types=1);

namespace TypeDock\Import;

/**
 * What a dry run found. Produced without writing anything, so an operator can
 * see what an import would do before committing to it — cheap to implement
 * and the single biggest thing that makes a migration feel safe.
 */
final class ImportScan
{
    /**
     * @param array<string, int>                            $counts   e.g. ['post' => 120, 'page' => 15]
     * @param array<int, string>                            $warnings
     * @param array<int, array{email:?string, name:string}> $authors  Distinct authors seen in the file
     */
    public function __construct(
        public readonly array $counts = [],
        public readonly array $warnings = [],
        public readonly array $authors = [],
        public readonly int $unmappedNodes = 0,
        public readonly ?string $sourceSiteUrl = null,
    ) {
    }

    public function documentCount(): int
    {
        return array_sum($this->counts);
    }
}
