<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

final class ExternalSourceAdapterRegistry
{
    /** @var array<string, ExternalSourceAdapterInterface> */
    private array $adapters = [];

    public static function withBuiltIns(): self
    {
        $registry = new self();
        $registry->register(new WordPressRestAdapter());
        $registry->register(new GenericJsonAdapter());
        return $registry;
    }

    public function register(ExternalSourceAdapterInterface $adapter): void
    {
        $id = $adapter->metadata()->id;
        if ($id === '') {
            throw new \InvalidArgumentException('External Source adapter id cannot be empty.');
        }
        if (isset($this->adapters[$id])) {
            throw new \InvalidArgumentException('External Source adapter is already registered: ' . $id);
        }
        $this->adapters[$id] = $adapter;
    }

    public function has(string $id): bool
    {
        return isset($this->adapters[$id]);
    }

    public function get(string $id): ?ExternalSourceAdapterInterface
    {
        return $this->adapters[$id] ?? null;
    }

    public function require(string $id): ExternalSourceAdapterInterface
    {
        return $this->get($id) ?? throw new \RuntimeException('Unsupported External Source provider: ' . $id);
    }

    public function first(): ExternalSourceAdapterInterface
    {
        $first = reset($this->adapters);
        if (!$first instanceof ExternalSourceAdapterInterface) {
            throw new \RuntimeException('No External Source adapters are registered.');
        }
        return $first;
    }

    /**
     * @return array<string, ExternalSourceAdapterInterface>
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
