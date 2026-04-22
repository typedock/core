<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration\Grammar;

use TypeDock\Core\Migration\Column;

final class SqliteGrammar extends Grammar
{
    protected function columnType(Column $column): string
    {
        return match ($column->type) {
            'string'     => sprintf('VARCHAR(%d)', $column->length ?? 255),
            'text'       => 'TEXT',
            'integer'    => 'INTEGER',
            'bigInteger' => 'INTEGER',
            'boolean'    => 'INTEGER',
            'float'      => 'REAL',
            'datetime'   => 'DATETIME',
            'timestamp'  => 'DATETIME',
            default      => throw new \InvalidArgumentException("Unsupported column type: {$column->type}"),
        };
    }
}
