<?php
declare(strict_types=1);

namespace TypeDock\Module\Collection;

use Ramsey\Uuid\Uuid;
use TypeDock\Exception\NotFoundException;
use TypeDock\Exception\ValidationException;

/**
 * Service for structured-data Collections (separate from free-form Pages).
 * A Collection defines a typed set of items; each item has a JSON-encoded data payload
 * matching the collection schema.
 */
class CollectionService
{
    public function __construct(private readonly \PDO $pdo) {}

    // ---- Collections ----------------------------------------------------

    /** @return array<array<string, mixed>> */
    public function listCollections(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM collections ORDER BY name ASC');
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** @return array<string, mixed>|null */
    public function findCollection(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM collections WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $this->decodeCollection($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findCollectionByHandle(string $handle): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM collections WHERE handle = ? LIMIT 1');
        $stmt->execute([$handle]);
        $row = $stmt->fetch();
        return $row !== false ? $this->decodeCollection($row) : null;
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createCollection(array $data): array
    {
        $handle = trim((string) ($data['handle'] ?? ''));
        $name   = trim((string) ($data['name'] ?? ''));

        if ($handle === '' || !preg_match('/^[a-z0-9_\-]+$/', $handle)) {
            throw new ValidationException('Invalid collection handle.');
        }
        if ($name === '') {
            throw new ValidationException('Collection name is required.');
        }
        if ($this->findCollectionByHandle($handle) !== null) {
            throw new ValidationException("Collection handle '{$handle}' already exists.");
        }

        $now    = $this->now();
        $id     = Uuid::uuid7()->toString();
        $schema = isset($data['schema']) ? json_encode($data['schema']) : null;

        $this->pdo->prepare(
            'INSERT INTO collections (id, handle, name, description, schema, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $handle, $name, $data['description'] ?? null, $schema, $now, $now]);

        return $this->findCollection($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateCollection(string $id, array $data): array
    {
        $col = $this->findCollection($id);
        if ($col === null) {
            throw new NotFoundException("Collection not found: {$id}");
        }
        $schema = isset($data['schema']) ? json_encode($data['schema']) : ($col['schema_raw'] ?? null);

        $this->pdo->prepare(
            'UPDATE collections SET name = ?, description = ?, schema = ?, updated_at = ? WHERE id = ?'
        )->execute([
            $data['name'] ?? $col['name'],
            $data['description'] ?? $col['description'],
            $schema,
            $this->now(),
            $id,
        ]);
        return $this->findCollection($id);
    }

    public function deleteCollection(string $id): void
    {
        $this->pdo->prepare('DELETE FROM collection_items WHERE collection_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
    }

    // ---- Items ----------------------------------------------------------

    /**
     * @param  array<string, mixed> $options
     * @return array{items: array<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listItems(string $collectionId, array $options = []): array
    {
        $perPage = min((int) ($options['per_page'] ?? 50), 200);
        $page    = max(1, (int) ($options['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $where  = ['collection_id = ?'];
        $params = [$collectionId];

        if (isset($options['status'])) {
            $where[]  = 'status = ?';
            $params[] = $options['status'];
        }
        if (isset($options['locale'])) {
            $where[]  = 'locale = ?';
            $params[] = $options['locale'];
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM collection_items WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listParams = array_merge($params, [$perPage, $offset]);
        $stmt       = $this->pdo->prepare(
            "SELECT * FROM collection_items WHERE {$whereSql}
             ORDER BY sort_order ASC, created_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute($listParams);
        $rows = array_map([$this, 'decodeItem'], $stmt->fetchAll());

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    public function findItem(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM collection_items WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $this->decodeItem($row) : null;
    }

    public function findItemBySlug(string $collectionId, string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM collection_items WHERE collection_id = ? AND slug = ? LIMIT 1'
        );
        $stmt->execute([$collectionId, $slug]);
        $row = $stmt->fetch();
        return $row !== false ? $this->decodeItem($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createItem(string $collectionId, array $data): array
    {
        if ($this->findCollection($collectionId) === null) {
            throw new NotFoundException("Collection not found: {$collectionId}");
        }

        $title = trim((string) ($data['title'] ?? ''));
        $slug  = trim((string) ($data['slug'] ?? ''));

        if ($title === '') {
            throw new ValidationException('Item title is required.');
        }
        if ($slug === '') {
            $slug = $this->slugify($title);
        }
        if (!preg_match('/^[a-z0-9\-_]+$/i', $slug)) {
            throw new ValidationException('Invalid item slug.');
        }
        if ($this->findItemBySlug($collectionId, $slug) !== null) {
            throw new ValidationException("Slug already exists in this collection: {$slug}");
        }

        $now  = $this->now();
        $id   = Uuid::uuid7()->toString();
        $body = isset($data['data']) ? json_encode($data['data']) : null;

        $this->pdo->prepare(
            'INSERT INTO collection_items
                (id, collection_id, slug, title, data, status, locale, sort_order,
                 published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $collectionId,
            $slug,
            $title,
            $body,
            $data['status'] ?? 'published',
            $data['locale'] ?? 'en',
            (int) ($data['sort_order'] ?? 0),
            $data['published_at'] ?? $now,
            $now,
            $now,
        ]);

        return $this->findItem($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateItem(string $id, array $data): array
    {
        $item = $this->findItem($id);
        if ($item === null) {
            throw new NotFoundException("Item not found: {$id}");
        }
        $body = isset($data['data']) ? json_encode($data['data']) : ($item['data_raw'] ?? null);

        $this->pdo->prepare(
            'UPDATE collection_items
                SET slug = ?, title = ?, data = ?, status = ?, locale = ?,
                    sort_order = ?, published_at = ?, updated_at = ?
              WHERE id = ?'
        )->execute([
            $data['slug'] ?? $item['slug'],
            $data['title'] ?? $item['title'],
            $body,
            $data['status'] ?? $item['status'],
            $data['locale'] ?? $item['locale'],
            (int) ($data['sort_order'] ?? $item['sort_order']),
            $data['published_at'] ?? $item['published_at'],
            $this->now(),
            $id,
        ]);

        return $this->findItem($id);
    }

    public function deleteItem(string $id): void
    {
        $this->pdo->prepare('DELETE FROM collection_items WHERE id = ?')->execute([$id]);
    }

    // ---- helpers --------------------------------------------------------

    private function decodeCollection(array $row): array
    {
        $row['schema_raw'] = $row['schema'] ?? null;
        $row['schema']     = $row['schema'] ? (json_decode((string) $row['schema'], true) ?: []) : [];
        return $row;
    }

    private function decodeItem(array $row): array
    {
        $row['data_raw'] = $row['data'] ?? null;
        $row['data']     = $row['data'] ? (json_decode((string) $row['data'], true) ?: []) : [];
        return $row;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        return trim($slug, '-') ?: 'item';
    }
}
