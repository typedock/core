<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration;

final class ForeignKey
{
    public string $onDelete = 'NO ACTION';
    public string $onUpdate = 'NO ACTION';

    public function __construct(
        public string $column,
        public string $referencesTable,
        public string $referencesColumn = 'id',
    ) {
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = strtoupper(str_replace('_', ' ', $action));
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = strtoupper(str_replace('_', ' ', $action));
        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('CASCADE');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('SET NULL');
    }
}
