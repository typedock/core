<?php
declare(strict_types=1);

namespace TypeDock\Content;

class TagService
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
        if (isset($options['search'])) {
            $where[]  = 'name LIKE ?';
            $params[] = '%' . $options['search'] . '%';
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM tags WHERE ' . implode(' AND ', $where) . ' ORDER BY name LIMIT 500'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tags WHERE id = ? LIMIT 1');
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
        $stmt = $this->pdo->prepare('SELECT * FROM tags WHERE slug = ? AND locale = ? LIMIT 1');
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
        $id   = typedock_uuid7();
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $slug = trim((string) ($data['slug'] ?? ''));
        $slug = $slug !== ''
            ? TermSlugger::normalize($slug, 'tag-' . date('YmdHis'))
            : TermSlugger::fromName((string) ($data['name'] ?? ''), 'tag');

        $locale = $this->normalizeLocale(isset($data['locale']) ? (string) $data['locale'] : null);
        $this->ensureUniqueSlug($slug, $locale);

        $stmt = $this->pdo->prepare(
            'INSERT INTO tags (id, slug, name, locale, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $slug, $data['name'] ?? '', $locale, $now]);

        return $this->find($id);
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
    }

    /**
     * Find or create tags by name. Returns array of tag IDs.
     *
     * @param  array<string> $names
     * @return array<string>
     */
    public function findOrCreateByNames(array $names, ?string $locale = null): array
    {
        $locale = $this->normalizeLocale($locale);
        $ids = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $slug = TermSlugger::fromName($name, 'tag');
            $stmt = $this->pdo->prepare('SELECT id FROM tags WHERE slug = ? AND locale = ? LIMIT 1');
            $stmt->execute([$slug, $locale]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $ids[] = (string) $row['id'];
            } else {
                $tag   = $this->create(['name' => $name, 'slug' => $slug, 'locale' => $locale]);
                $ids[] = (string) $tag['id'];
            }
        }
        return $ids;
    }

    private function ensureUniqueSlug(string $slug, string $locale): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM tags WHERE slug = ? AND locale = ? LIMIT 1');
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
