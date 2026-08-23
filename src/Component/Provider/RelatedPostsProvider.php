<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;
use TypeDock\Content\PostView;

class RelatedPostsProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $count = min((int) ($params['count'] ?? 6), 20);
        if ($context->page === null) {
            return ['posts' => []];
        }

        $pageId = (string) ($context->page['id'] ?? '');
        $pdo    = \Flight::db();

        $stmt = $pdo->prepare('SELECT category_id FROM post_categories WHERE post_id = ?');
        $stmt->execute([$pageId]);
        $catIds = array_column($stmt->fetchAll(), 'category_id');
        if ($catIds === []) {
            return ['posts' => []];
        }

        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count($catIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT p.id, p.slug, p.title, p.body, p.excerpt, p.published_at, p.updated_at, p.post_type,
                    COALESCE(NULLIF(u.display_name, ''), u.name) as author_name,
                    u.slug as author_slug,
                    sm.og_image_id
             FROM posts p
             JOIN post_categories pc ON pc.post_id = p.id
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN seo_meta sm ON sm.target_type = p.post_type AND sm.target_id = p.id
             WHERE pc.category_id IN ({$placeholders})
               AND p.id != ?
               AND p.status = 'published'
               AND (p.published_at IS NULL OR p.published_at <= ?)
               AND p.locale = ?
             ORDER BY p.published_at DESC LIMIT ?"
        );
        $stmt->execute(array_merge($catIds, [$pageId, $now, $context->locale !== '' ? $context->locale : typedock_current_locale(), $count]));

        return ['posts' => PostView::projectList($stmt->fetchAll())];
    }
}
