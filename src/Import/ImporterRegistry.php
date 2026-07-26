<?php
declare(strict_types=1);

namespace TypeDock\Import;

final class ImporterRegistry
{
    /** @var array<string, ImporterInterface> */
    private array $importers = [];

    public function register(ImporterInterface $importer): void
    {
        $this->importers[$importer->key()] = $importer;
    }

    public function get(string $key): ?ImporterInterface
    {
        return $this->importers[$key] ?? null;
    }

    /** @return array<string, ImporterInterface> */
    public function all(): array
    {
        return $this->importers;
    }

    /** @return array<int, string> */
    public function keys(): array
    {
        return array_keys($this->importers);
    }
}
