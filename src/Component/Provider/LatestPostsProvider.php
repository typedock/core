<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class LatestPostsProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $count = min((int) ($params['count'] ?? 5), 20);
        $pdo   = \Flight::db();
        $stmt  = $pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.excerpt, p.published_at, u.name as author_name
             FROM pages p
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.page_type = 'post' AND p.status = 'published'
             ORDER BY p.published_at DESC LIMIT ?"
        );
        $stmt->execute([$count]);
        return ['posts' => $stmt->fetchAll()];
    }
}
