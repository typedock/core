<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration\Grammar;

use TypeDock\Core\Migration\Column;

final class PostgresGrammar extends Grammar
{
    protected function columnType(Column $column): string
    {
        return match ($column->type) {
            'string'     => sprintf('VARCHAR(%d)', $column->length ?? 255),
            'text'       => 'TEXT',
            'integer'    => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'boolean'    => 'BOOLEAN',
            'float'      => 'DOUBLE PRECISION',
            'datetime'   => 'TIMESTAMP',
            'timestamp'  => 'TIMESTAMP',
            default      => throw new \InvalidArgumentException("Unsupported column type: {$column->type}"),
        };
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }
}
