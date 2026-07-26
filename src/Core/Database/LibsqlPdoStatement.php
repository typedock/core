<?php
declare(strict_types=1);

namespace TypeDock\Core\Database;

use Closure;
use Iterator;
use PDO;
use PDOException;
use PDOStatement;

/**
 * PDOStatement subset paired with the experimental LibsqlPdo adapter.
 */
final class LibsqlPdoStatement extends PDOStatement
{
    /** @var array<int|string,mixed> */
    private array $boundParams = [];
    /** @var list<array<string,mixed>> */
    private array $rows = [];
    private int $cursor = 0;
    private int $affectedRows = 0;
    private int $columnCountValue = 0;

    /**
     * @param Closure(string,array<int|string,mixed>):array{rows:list<array<string,mixed>>,affected:int,columns:int} $executor
     */
    public function __construct(
        private readonly string $sql,
        private readonly Closure $executor,
        private int $fetchMode = PDO::FETCH_ASSOC,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $effective = $params === null
            ? $this->boundParams
            : array_replace($this->boundParams, $params);

        $result = ($this->executor)($this->sql, $effective);
        $this->rows = $result['rows'];
        $this->cursor = 0;
        $this->affectedRows = $result['affected'];
        $this->columnCountValue = $result['columns'];

        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $key = is_int($param) ? max(0, $param - 1) : $param;
        $this->boundParams[$key] = match ($type) {
            PDO::PARAM_NULL => null,
            PDO::PARAM_BOOL => (bool) $value,
            PDO::PARAM_INT => (int) $value,
            PDO::PARAM_STR => (string) $value,
            PDO::PARAM_LOB => new HranaBlob($this->lobContents($value)),
            default => $value,
        };
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        if ($cursorOrientation !== PDO::FETCH_ORI_NEXT) {
            throw new PDOException('The libSQL driver supports forward-only cursors.');
        }
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }

        $row = $this->rows[$this->cursor++];
        return $this->formatRow($row, $mode);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $out = [];
        while (isset($this->rows[$this->cursor])) {
            $row = $this->rows[$this->cursor++];
            if ($mode === PDO::FETCH_COLUMN || ($mode === PDO::FETCH_DEFAULT && $this->fetchMode === PDO::FETCH_COLUMN)) {
                $out[] = array_values($row)[(int) ($args[0] ?? 0)] ?? false;
                continue;
            }
            $out[] = $this->formatRow($row, $mode);
        }
        return $out;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }
        $values = array_values($this->rows[$this->cursor++]);
        return $values[$column] ?? false;
    }

    public function rowCount(): int
    {
        return $this->affectedRows;
    }

    public function columnCount(): int
    {
        return $this->columnCountValue;
    }

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;
        return true;
    }

    public function closeCursor(): bool
    {
        $this->cursor = count($this->rows);
        return true;
    }

    public function getIterator(): Iterator
    {
        while (($row = $this->fetch()) !== false) {
            yield $row;
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function formatRow(array $row, int $mode): mixed
    {
        $mode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;

        return match ($mode) {
            PDO::FETCH_ASSOC, PDO::FETCH_NAMED => $row,
            PDO::FETCH_NUM => array_values($row),
            PDO::FETCH_BOTH => array_merge($row, array_values($row)),
            PDO::FETCH_OBJ => (object) $row,
            PDO::FETCH_COLUMN => array_values($row)[0] ?? false,
            default => throw new PDOException("Unsupported libSQL fetch mode: {$mode}"),
        };
    }

    private function lobContents(mixed $value): string
    {
        if (!is_resource($value)) {
            return (string) $value;
        }

        $contents = stream_get_contents($value);
        return $contents === false ? '' : $contents;
    }
}
