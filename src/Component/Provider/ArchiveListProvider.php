<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class ArchiveListProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $pdo  = \Flight::db();

        // Try MySQL/SQLite approach first
        try {
            $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare(
                "SELECT SUBSTR(published_at, 1, 7) as month, COUNT(*) as count
                 FROM posts
                 WHERE post_type = 'post' AND status = 'published'
                   AND (published_at IS NULL OR published_at <= ?)
                   AND locale = ?
                 GROUP BY SUBSTR(published_at, 1, 7)
                 ORDER BY month DESC LIMIT 24"
            );
            $stmt->execute([$now, $context->locale !== '' ? $context->locale : typedock_current_locale()]);
            $archives = $stmt->fetchAll();
        } catch (\Throwable) {
            $archives = [];
        }

        return ['archives' => $archives];
    }
}
