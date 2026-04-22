<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration\Grammar;

use TypeDock\Core\Migration\Blueprint;
use TypeDock\Core\Migration\Column;
use TypeDock\Core\Migration\ForeignKey;
use TypeDock\Core\Migration\Index;

/**
 * Base SQL generator. Emits CREATE TABLE + CREATE INDEX statements for a Blueprint.
 * Foreign keys are always inlined in CREATE TABLE; indexes are always emitted as
 * separate CREATE INDEX statements (works uniformly on MySQL/Postgres/SQLite).
 */
abstract class Grammar
{
    /**
     * Compile a Blueprint into one or more SQL statements.
     *
     * @return string[] SQL statements to execute in order
     */
    public function compileCreate(Blueprint $bp): array
    {
        $lines = [];
        foreach ($bp->columns as $column) {
            $lines[] = '  ' . $this->compileColumn($column);
        }

        if ($bp->primaryKey !== []) {
            $lines[] = '  PRIMARY KEY (' . $this->quoteList($bp->primaryKey) . ')';
        }

        foreach ($bp->foreignKeys as $fk) {
            $lines[] = '  ' . $this->compileForeignKey($bp, $fk);
        }

        $sql = 'CREATE TABLE ' . $this->quote($bp->table) . " (\n"
            . implode(",\n", $lines) . "\n)"
            . $this->tableSuffix();

        $statements = [$sql];

        foreach ($bp->indexes as $index) {
            $statements[] = $this->compileCreateIndex($bp, $index);
        }

        return $statements;
    }

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE ' . $this->quote($table);
    }

    public function compileDropIfExists(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->quote($table);
    }

    public function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /** @param string[] $columns */
    protected function quoteList(array $columns): string
    {
        return implode(', ', array_map(fn($c) => $this->quote($c), $columns));
    }

    protected function tableSuffix(): string
    {
        return '';
    }

    protected function compileColumn(Column $column): string
    {
        $sql = $this->quote($column->name) . ' ' . $this->columnType($column);
        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';
        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($column);
        }
        return $sql;
    }

    protected function compileForeignKey(Blueprint $bp, ForeignKey $fk): string
    {
        $name = $this->indexName('fk', $bp->table, [$fk->column]);
        return sprintf(
            'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
            $this->quote($name),
            $this->quote($fk->column),
            $this->quote($fk->referencesTable),
            $this->quote($fk->referencesColumn),
            $fk->onDelete,
            $fk->onUpdate,
        );
    }

    protected function compileCreateIndex(Blueprint $bp, Index $index): string
    {
        $name = $this->indexName($index->unique ? 'uniq' : 'idx', $bp->table, $index->columns);
        $columns = $this->quoteList($index->columns);
        $type = $index->unique ? 'UNIQUE INDEX' : 'INDEX';
        return sprintf(
            'CREATE %s %s ON %s (%s)',
            $type,
            $this->quote($name),
            $this->quote($bp->table),
            $columns,
        );
    }

    /** @param string[] $columns */
    protected function indexName(string $prefix, string $table, array $columns): string
    {
        $name = $prefix . '_' . $table . '_' . implode('_', $columns);
        // MySQL identifier limit is 64 chars; keep under it for all DBs.
        if (strlen($name) <= 64) {
            return $name;
        }
        return substr($name, 0, 50) . '_' . substr(sha1($name), 0, 10);
    }

    protected function defaultValue(Column $column): string
    {
        if ($column->useCurrent) {
            return 'CURRENT_TIMESTAMP';
        }
        $value = $column->default;
        if ($value === null) {
            return 'NULL';
        }
        if (is_bool($value)) {
            return $this->booleanLiteral($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    protected function booleanLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    abstract protected function columnType(Column $column): string;
}
