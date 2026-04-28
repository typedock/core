<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

interface ExternalSourceAdapterInterface
{
    public function metadata(): ExternalSourceAdapterMetadata;

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function list(array $source, array $credentials, int $limit, int $offset = 0): array;

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>|null
     */
    public function getBySlug(array $source, array $credentials, string $slug): ?array;

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     * @return array<int, array<string, mixed>>
     */
    public function discoverFields(array $source, array $credentials): array;
}
