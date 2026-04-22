<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration;

final class Blueprint
{
    /** @var Column[] */
    public array $columns = [];

    /** @var string[] */
    public array $primaryKey = [];

    /** @var Index[] */
    public array $indexes = [];

    /** @var ForeignKey[] */
    public array $foreignKeys = [];

    public function __construct(
        public string $table,
        public string $driver,
    ) {
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->add(new Column($name, 'string', $length));
    }

    public function text(string $name): Column
    {
        return $this->add(new Column($name, 'text'));
    }

    public function integer(string $name): Column
    {
        return $this->add(new Column($name, 'integer'));
    }

    public function bigInteger(string $name): Column
    {
        return $this->add(new Column($name, 'bigInteger'));
    }

    public function boolean(string $name): Column
    {
        return $this->add(new Column($name, 'boolean'));
    }

    public function float(string $name): Column
    {
        return $this->add(new Column($name, 'float'));
    }

    public function datetime(string $name): Column
    {
        return $this->add(new Column($name, 'datetime'));
    }

    public function timestamp(string $name): Column
    {
        return $this->add(new Column($name, 'timestamp'));
    }

    /** @param string[] $columns */
    public function primary(array $columns): self
    {
        $this->primaryKey = $columns;
        return $this;
    }

    /**
     * @param string[]          $columns
     * @param array<string,int> $mysqlPrefix Per-column prefix length (MySQL only, for long VARCHAR)
     */
    public function index(array $columns, array $mysqlPrefix = []): self
    {
        $this->indexes[] = new Index($columns, false, $mysqlPrefix);
        return $this;
    }

    /**
     * @param string[]          $columns
     * @param array<string,int> $mysqlPrefix Per-column prefix length (MySQL only, for long VARCHAR)
     */
    public function unique(array $columns, array $mysqlPrefix = []): self
    {
        $this->indexes[] = new Index($columns, true, $mysqlPrefix);
        return $this;
    }

    public function foreign(string $column, string $referencesTable, string $referencesColumn = 'id'): ForeignKey
    {
        $fk = new ForeignKey($column, $referencesTable, $referencesColumn);
        $this->foreignKeys[] = $fk;
        return $fk;
    }

    private function add(Column $column): Column
    {
        $this->columns[] = $column;
        return $column;
    }
}
