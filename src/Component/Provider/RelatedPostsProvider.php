<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class RelatedPostsProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $count = min((int) ($params['count'] ?? 6), 20);
        $posts = [];

        if ($context->page !== null) {
            $pageId = (string) ($context->page['id'] ?? '');
            $pdo    = \Flight::db();

            // Get categories of current page
            $stmt = $pdo->prepare(
                'SELECT category_id FROM page_categories WHERE page_id = ?'
            );
            $stmt->execute([$pageId]);
            $catIds = array_column($stmt->fetchAll(), 'category_id');

            if (!empty($catIds)) {
                $placeholders = implode(',', array_fill(0, count($catIds), '?'));
                $stmt = $pdo->prepare(
                    "SELECT DISTINCT p.id, p.slug, p.title, p.excerpt, p.published_at
                     FROM pages p
                     JOIN page_categories pc ON pc.page_id = p.id
                     WHERE pc.category_id IN ({$placeholders})
                       AND p.id != ?
                       AND p.status = 'published'
                     ORDER BY p.published_at DESC LIMIT ?"
                );
                $stmt->execute(array_merge($catIds, [$pageId, $count]));
                $posts = $stmt->fetchAll();
            }
        }

        return ['posts' => $posts];
    }
}
