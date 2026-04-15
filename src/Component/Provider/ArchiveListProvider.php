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
            $stmt = $pdo->prepare(
                "SELECT SUBSTR(published_at, 1, 7) as month, COUNT(*) as count
                 FROM pages
                 WHERE page_type = 'post' AND status = 'published'
                 GROUP BY SUBSTR(published_at, 1, 7)
                 ORDER BY month DESC LIMIT 24"
            );
            $stmt->execute();
            $archives = $stmt->fetchAll();
        } catch (\Throwable) {
            $archives = [];
        }

        return ['archives' => $archives];
    }
}
