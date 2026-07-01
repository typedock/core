<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;
use TypeDock\Content\PostView;

class LatestPostsProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $count = min((int) ($params['count'] ?? 5), 20);
        $stmt  = \Flight::db()->prepare(
            "SELECT p.id, p.slug, p.title, p.body, p.excerpt, p.published_at, p.updated_at, p.post_type,
                    COALESCE(NULLIF(u.display_name, ''), u.name) as author_name,
                    u.slug as author_slug,
                    sm.og_image_id
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN seo_meta sm ON sm.target_type = p.post_type AND sm.target_id = p.id
             WHERE p.post_type = 'post' AND p.status = 'published'
               AND p.locale = ?
             ORDER BY p.published_at DESC LIMIT ?"
        );
        $stmt->execute([$context->locale !== '' ? $context->locale : typedock_current_locale(), $count]);
        return ['posts' => PostView::projectList($stmt->fetchAll())];
    }
}
