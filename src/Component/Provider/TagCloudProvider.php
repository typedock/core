<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class TagCloudProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $now   = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $limit = min((int) ($params['limit'] ?? 30), 100);
        $pdo   = \Flight::db();
        $stmt  = $pdo->prepare(
            "SELECT t.id, t.slug, t.name, COUNT(p.id) as post_count
             FROM tags t
             LEFT JOIN post_tags pt ON pt.tag_id = t.id
             LEFT JOIN posts p ON p.id = pt.post_id AND p.status = 'published' AND (p.published_at IS NULL OR p.published_at <= ?) AND p.locale = t.locale
             WHERE t.locale = ?
             GROUP BY t.id, t.slug, t.name
             ORDER BY post_count DESC, t.name ASC
             LIMIT ?"
        );
        $stmt->execute([$now, $context->locale, $limit]);
        return ['tags' => $stmt->fetchAll()];
    }
}
