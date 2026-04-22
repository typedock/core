<?php
declare(strict_types=1);

namespace TypeDock\Core\Migration;

final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $useCurrent = false;

    public function __construct(
        public string $name,
        public string $type,
        public ?int $length = null,
    ) {
    }

    public function null(bool $nullable = true): self
    {
        $this->nullable = $nullable;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    public function useCurrent(): self
    {
        $this->useCurrent = true;
        $this->hasDefault = true;
        return $this;
    }
}
