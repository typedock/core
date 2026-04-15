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
        $where  = ["p.status = 'published'"];
        $params = [];

        if (!empty($options['page_type'])) {
            $where[]  = 'p.page_type = ?';
            $params[] = $options['page_type'];
        }

        if (!empty($options['locale'])) {
            $where[]  = 'p.locale = ?';
            $params[] = $options['locale'];
        }

        // Build LIKE conditions for each term
        foreach ($terms as $term) {
            $where[]  = '(p.title LIKE ? OR p.excerpt LIKE ?)';
            $like     = '%' . $term . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $whereStr = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM pages p WHERE {$whereStr}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $perPage = min((int) ($options['per_page'] ?? 20), 100);
        $page    = max(1, (int) ($options['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.excerpt, p.page_type, p.published_at, u.name as author_name
             FROM pages p
             LEFT JOIN users u ON u.id = p.author_id
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
