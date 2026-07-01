<?php
declare(strict_types=1);

namespace TypeDock\Content;

class CategoryService
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * @param  array<string, mixed> $options
     * @return array<array<string, mixed>>
     */
    public function list(array $options = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (isset($options['locale'])) {
            $where[]  = 'locale = ?';
            $params[] = $options['locale'];
        }
        if (isset($options['parent_id'])) {
            $where[]  = 'parent_id = ?';
            $params[] = $options['parent_id'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM categories WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order, name'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug, ?string $locale = null): ?array
    {
        $slug = TermSlugger::normalize($slug, $slug);
        $locale = $this->normalizeLocale($locale);
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE slug = ? AND locale = ? LIMIT 1');
        $stmt->execute([$slug, $locale]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $id   = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $slug = trim((string) ($data['slug'] ?? ''));
        $slug = $slug !== ''
            ? TermSlugger::normalize($slug, 'category-' . date('YmdHis'))
            : TermSlugger::fromName((string) ($data['name'] ?? ''), 'category');

        $locale = $this->normalizeLocale(isset($data['locale']) ? (string) $data['locale'] : null);
        $this->ensureUniqueSlug($slug, $locale);

        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (id, slug, name, description, parent_id, locale, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $slug,
            $data['name'] ?? '',
            $data['description'] ?? null,
            $data['parent_id'] ?? null,
            $locale,
            (int) ($data['sort_order'] ?? 0),
            $now,
        ]);

        return $this->find($id);
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $cat = $this->find($id);
        if ($cat === null) {
            throw new \TypeDock\Exception\NotFoundException("Category not found: {$id}");
        }

        $stmt = $this->pdo->prepare(
            'UPDATE categories SET slug = ?, name = ?, description = ?, parent_id = ?, sort_order = ? WHERE id = ?'
        );
        $stmt->execute([
            isset($data['slug']) && trim((string) $data['slug']) !== ''
                ? TermSlugger::normalize((string) $data['slug'], (string) $cat['slug'])
                : $cat['slug'],
            $data['name'] ?? $cat['name'],
            $data['description'] ?? $cat['description'],
            $data['parent_id'] ?? $cat['parent_id'],
            $data['sort_order'] ?? $cat['sort_order'],
            $id,
        ]);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        // Move children to parent
        $cat = $this->find($id);
        if ($cat !== null && !empty($cat['parent_id'])) {
            $this->pdo->prepare('UPDATE categories SET parent_id = ? WHERE parent_id = ?')
                ->execute([$cat['parent_id'], $id]);
        } else {
            $this->pdo->prepare('UPDATE categories SET parent_id = NULL WHERE parent_id = ?')
                ->execute([$id]);
        }
        $this->pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
    }

    private function ensureUniqueSlug(string $slug, string $locale): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM categories WHERE slug = ? AND locale = ? LIMIT 1');
        $stmt->execute([$slug, $locale]);
        if ($stmt->fetch() !== false) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['This slug is already in use.']]
            );
        }
    }

    private function normalizeLocale(?string $locale): string
    {
        $locale = strtolower(trim((string) ($locale ?? typedock_default_locale())));
        return $locale !== '' ? $locale : 'en';
    }
}
