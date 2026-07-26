<?php
declare(strict_types=1);

namespace TypeDock\Content;

class PostService
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_REVIEW    = 'review';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_TRASH     = 'trash';

    public const TYPE_POST = 'post';
    public const TYPE_PAGE = 'page';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly SlugValidator $slugValidator = new SlugValidator()
    ) {}

    /**
     * List pages with filtering and pagination.
     *
     * @param  array<string, mixed> $options
     * @return array{items: array<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function list(array $options = []): array
    {
        $where    = ['p.status != ?'];
        $params   = [self::STATUS_TRASH];
        $perPage  = min((int) ($options['per_page'] ?? 20), 100);
        $page     = max(1, (int) ($options['page'] ?? 1));
        $offset   = ($page - 1) * $perPage;

        if (isset($options['status'])) {
            $where[]  = 'p.status = ?';
            $params[] = $options['status'];
        }
        if (isset($options['post_type'])) {
            $where[]  = 'p.post_type = ?';
            $params[] = $options['post_type'];
        }
        if (isset($options['author_id'])) {
            $where[]  = 'p.author_id = ?';
            $params[] = $options['author_id'];
        }
        if (isset($options['locale'])) {
            $where[]  = 'p.locale = ?';
            $params[] = $options['locale'];
        }
        if (isset($options['search'])) {
            $where[]  = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.body_markdown LIKE ?)';
            $params[] = '%' . $options['search'] . '%';
            $params[] = '%' . $options['search'] . '%';
            $params[] = '%' . $options['search'] . '%';
        }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM posts p WHERE {$whereStr}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $order = match ($options['order_by'] ?? 'updated_at') {
            'published_at' => 'p.published_at DESC',
            'title'        => 'p.title ASC',
            default        => 'p.updated_at DESC',
        };

        $listParams   = array_merge($params, [$perPage, $offset]);
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.body, p.excerpt, p.post_type, p.status,
                    p.author_id, p.locale, p.published_at, p.created_at, p.updated_at,
                    COALESCE(NULLIF(u.display_name, ''), u.name) as author_name,
                    sm.og_image_id
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN seo_meta sm ON sm.target_type = p.post_type AND sm.target_id = p.id
             WHERE {$whereStr}
             ORDER BY {$order}
             LIMIT ? OFFSET ?"
        );
        $stmt->execute($listParams);
        $items = $stmt->fetchAll();
        $items = $this->decorateRows($items);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Find a single page by ID.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, u.name as author_name
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Find page by slug.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug, ?string $locale = null, ?string $status = null): ?array
    {
        $locale = strtolower(trim((string) ($locale ?? typedock_default_locale()))) ?: 'en';
        $sql    = 'SELECT p.*, u.name as author_name FROM posts p LEFT JOIN users u ON u.id = p.author_id WHERE p.slug = ? AND p.locale = ?';
        $params = [$slug, $locale];

        if ($status !== null) {
            $sql    .= ' AND p.status = ?';
            $params[] = $status;
        }

        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Create a new page.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $id   = \Ramsey\Uuid\Uuid::uuid7()->toString();

        $slug = $data['slug'] ?? '';
        if ($slug === '') {
            $slug = $this->slugValidator->generateUnique((string) ($data['title'] ?? 'post'), $this->pdo);
        } else {
            $this->slugValidator->validate($slug);
        }

        $postType = in_array($data['post_type'] ?? 'post', [self::TYPE_POST, self::TYPE_PAGE], true)
            ? $data['post_type']
            : self::TYPE_POST;

        $status = $data['status'] ?? self::STATUS_DRAFT;

        $publishedAt = null;
        if ($status === self::STATUS_PUBLISHED && empty($data['published_at'])) {
            $publishedAt = $now;
        } elseif (!empty($data['published_at'])) {
            $publishedAt = $data['published_at'];
        }

        $body = is_array($data['body'] ?? null) ? json_encode($data['body']) : ($data['body'] ?? null);
        $bodyMarkdown = TiptapMarkdownRenderer::render($body);

        $stmt = $this->pdo->prepare(
            'INSERT INTO posts (id, slug, title, body, body_markdown, excerpt, post_type, status, author_id, parent_id,
                                template, layout, locale, translation_group_id, published_at, scheduled_at,
                                created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $slug,
            $data['title'] ?? '',
            $body,
            $bodyMarkdown !== '' ? $bodyMarkdown : null,
            $data['excerpt'] ?? null,
            $postType,
            $status,
            $data['author_id'] ?? null,
            $data['parent_id'] ?? null,
            $data['template'] ?? null,
            $data['layout'] ?? null,
            strtolower(trim((string) ($data['locale'] ?? typedock_default_locale()))) ?: 'en',
            $data['translation_group_id'] ?? null,
            $publishedAt,
            $data['scheduled_at'] ?? null,
            $now,
            $now,
        ]);

        $this->syncCategories($id, $data['category_ids'] ?? []);
        $this->syncTags($id, $data['tag_ids'] ?? []);

        return $this->find($id);
    }

    /**
     * Update an existing page.
     *
     * `$createRevision` exists for bulk writers — re-running an import would
     * otherwise stack a meaningless revision onto every post in the site. Only
     * ImportWriter passes false; every editorial path keeps the default so
     * history stays intact.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data, bool $createRevision = true): array
    {
        $page = $this->find($id);
        if ($page === null) {
            throw new \TypeDock\Exception\NotFoundException("Page not found: {$id}");
        }

        if ($createRevision) {
            $this->saveRevision($page);
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if (isset($data['slug']) && $data['slug'] !== $page['slug']) {
            $this->slugValidator->validate($data['slug']);
        }

        $slug = $data['slug'] ?? $page['slug'];

        $status      = $data['status'] ?? $page['status'];
        $publishedAt = $page['published_at'];
        if ($status === self::STATUS_PUBLISHED && $page['status'] !== self::STATUS_PUBLISHED && $publishedAt === null) {
            $publishedAt = $now;
        } elseif (isset($data['published_at'])) {
            $publishedAt = $data['published_at'];
        }

        $body = isset($data['body'])
            ? (is_array($data['body']) ? json_encode($data['body']) : $data['body'])
            : $page['body'];
        $bodyMarkdown = TiptapMarkdownRenderer::render($body);

        // Defense in depth (doc24 #1): immutable identity columns. Even if a
        // crafted POST manages to slip `author_id` / `post_type` / `locale`
        // into $data, the service refuses to reassign them — those decisions
        // were made at create() time and changing them through edit would
        // bypass the controller's ownership / role checks (e.g. flipping a
        // page into a post would let an author publish "page-only" content).
        $stmt = $this->pdo->prepare(
            'UPDATE posts SET slug = ?, title = ?, body = ?, body_markdown = ?, excerpt = ?, post_type = ?,
                              status = ?, author_id = ?, parent_id = ?, template = ?, layout = ?,
                              locale = ?, published_at = ?, scheduled_at = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $slug,
            $data['title'] ?? $page['title'],
            $body,
            $bodyMarkdown !== '' ? $bodyMarkdown : null,
            $data['excerpt'] ?? $page['excerpt'],
            $page['post_type'],
            $status,
            $page['author_id'],
            $data['parent_id'] ?? $page['parent_id'],
            $data['template'] ?? $page['template'],
            array_key_exists('layout', $data) ? $data['layout'] : ($page['layout'] ?? null),
            $page['locale'],
            $publishedAt,
            $data['scheduled_at'] ?? $page['scheduled_at'],
            $now,
            $id,
        ]);

        if (isset($data['category_ids'])) {
            $this->syncCategories($id, $data['category_ids']);
        }
        if (isset($data['tag_ids'])) {
            $this->syncTags($id, $data['tag_ids']);
        }

        return $this->find($id);
    }

    /**
     * Move page to trash.
     */
    public function trash(string $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE posts SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([self::STATUS_TRASH, $now, $id]);
    }

    /**
     * Restore page from trash to draft.
     */
    public function restore(string $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE posts SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([self::STATUS_DRAFT, $now, $id]);
    }

    /**
     * Permanently delete a page.
     */
    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getCategories(string $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM categories c
             JOIN post_categories pc ON pc.category_id = c.id
             WHERE pc.post_id = ?
             ORDER BY c.sort_order, c.name'
        );
        $stmt->execute([$pageId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getTags(string $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM tags t
             JOIN post_tags pt ON pt.tag_id = t.id
             WHERE pt.post_id = ?
             ORDER BY t.name'
        );
        $stmt->execute([$pageId]);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string> $categoryIds
     */
    private function syncCategories(string $pageId, array $categoryIds): void
    {
        $this->pdo->prepare('DELETE FROM post_categories WHERE post_id = ?')->execute([$pageId]);
        $stmt = $this->pdo->prepare('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)');
        foreach (array_unique($categoryIds) as $catId) {
            $stmt->execute([$pageId, $catId]);
        }
    }

    /**
     * @param array<string> $tagIds
     */
    private function syncTags(string $pageId, array $tagIds): void
    {
        $this->pdo->prepare('DELETE FROM post_tags WHERE post_id = ?')->execute([$pageId]);
        $stmt = $this->pdo->prepare('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)');
        foreach (array_unique($tagIds) as $tagId) {
            $stmt->execute([$pageId, $tagId]);
        }
    }

    /**
     * @param array<string, mixed> $page
     */
    private function saveRevision(array $page): void
    {
        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO post_revisions (id, post_id, title, body, body_markdown, author_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $page['id'],
            $page['title'],
            $page['body'],
            $page['body_markdown'] ?? TiptapMarkdownRenderer::render($page['body'] ?? null),
            $page['author_id'],
            $now,
        ]);
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @return array<array<string, mixed>>
     */
    private function decorateRows(array $rows): array
    {
        $seo = new \TypeDock\Seo\SeoService($this->pdo);
        $global = $seo->findByTarget('global', null) ?? [];
        $globalOgImageId = isset($global['og_image_id']) ? (string) $global['og_image_id'] : null;
        foreach ($rows as &$row) {
            $row['excerpt'] = self::excerptFromRow($row);
            $ogImageId = !empty($row['og_image_id']) ? (string) $row['og_image_id'] : $globalOgImageId;
            $row['og_image_url'] = $seo->resolveOgImageUrl($ogImageId);
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function excerptFromRow(array $row, int $length = 120): string
    {
        $explicit = trim((string) ($row['excerpt'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $text = TiptapRenderer::toPlainText($row['body'] ?? null);
        if ($text === '') {
            return '';
        }
        if (mb_strlen($text, 'UTF-8') <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length, 'UTF-8')) . '…';
    }
}
