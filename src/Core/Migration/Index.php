<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration;

final class Index
{
    /**
     * @param string[]             $columns
     * @param array<string,int>    $mysqlPrefix Per-column index prefix length (MySQL only, for long VARCHAR)
     */
    public function __construct(
        public array $columns,
        public bool $unique = false,
        public array $mysqlPrefix = [],
    ) {
    }
}
