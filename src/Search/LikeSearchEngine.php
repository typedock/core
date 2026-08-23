<?php
declare(strict_types=1);

namespace TypeDock\Search;

use TypeDock\Contract\SearchEngine;

class LikeSearchEngine implements SearchEngine
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * @param  array<string, mixed> $options
     * @return array{items: array<mixed>, total: int}
     */
    public function search(string $query, array $options = []): array
    {
        if ($query === '') {
            return ['items' => [], 'total' => 0];
        }

        $terms  = $this->parseQuery($query);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $where  = ["p.status = 'published'", "(p.published_at IS NULL OR p.published_at <= ?)"];
        $params = [$now];

        if (!empty($options['post_type'])) {
            $where[]  = 'p.post_type = ?';
            $params[] = $options['post_type'];
        }

        if (!empty($options['locale'])) {
            $where[]  = 'p.locale = ?';
            $params[] = $options['locale'];
        }

        // Build LIKE conditions for each term against the authored metadata
        // and the generated Markdown projection of the Tiptap body.
        foreach ($terms as $term) {
            $where[]  = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.body_markdown LIKE ?)';
            $like     = '%' . $term . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM posts p WHERE {$whereStr}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = min((int) ($options['per_page'] ?? 20), 100);
        $page    = max(1, (int) ($options['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.excerpt, p.post_type, p.published_at, p.updated_at,
                    COALESCE(NULLIF(u.display_name, ''), u.name) as author_name,
                    u.slug as author_slug,
                    sm.og_image_id
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN seo_meta sm ON sm.target_type = p.post_type AND sm.target_id = p.id
             WHERE {$whereStr}
             ORDER BY p.published_at DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $items = $stmt->fetchAll();

        return compact('items', 'total');
    }

    /** @return array<string> */
    private function parseQuery(string $query): array
    {
        // Split by whitespace, filter empty, limit to 5 terms
        $terms = array_filter(
            array_slice(preg_split('/\s+/', trim($query)) ?: [], 0, 5),
            fn (string $t): bool => mb_strlen($t, 'UTF-8') >= 2
        );
        return array_values($terms);
    }
}
