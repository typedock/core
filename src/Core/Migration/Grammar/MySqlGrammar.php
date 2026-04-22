<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration\Grammar;

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Column;
use TypeDock\Core\Migration\Index;

final class MySqlGrammar extends Grammar
{
    public function quote(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function tableSuffix(): string
    {
        return ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }

    protected function columnType(Column $column): string
    {
        return match ($column->type) {
            'string'     => sprintf('VARCHAR(%d)', $column->length ?? 255),
            'text'       => 'TEXT',
            'integer'    => 'INT',
            'bigInteger' => 'BIGINT',
            'boolean'    => 'TINYINT(1)',
            'float'      => 'FLOAT',
            'datetime'   => 'DATETIME',
            'timestamp'  => 'TIMESTAMP NULL',
            default      => throw new \InvalidArgumentException("Unsupported column type: {$column->type}"),
        };
    }

    protected function compileCreateIndex(Blueprint $bp, Index $index): string
    {
        $name = $this->indexName($index->unique ? 'uniq' : 'idx', $bp->table, $index->columns);
        $cols = [];
        foreach ($index->columns as $col) {
            $quoted = $this->quote($col);
            if (isset($index->mysqlPrefix[$col])) {
                $quoted .= '(' . (int) $index->mysqlPrefix[$col] . ')';
            }
            $cols[] = $quoted;
        }
        $type = $index->unique ? 'UNIQUE INDEX' : 'INDEX';
        return sprintf(
            'CREATE %s %s ON %s (%s)',
            $type,
            $this->quote($name),
            $this->quote($bp->table),
            implode(', ', $cols),
        );
    }
}
