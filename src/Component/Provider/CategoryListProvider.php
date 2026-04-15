<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class CategoryListProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare(
            'SELECT c.id, c.slug, c.name, c.parent_id,
                    COUNT(pc.page_id) as post_count
             FROM categories c
             LEFT JOIN page_categories pc ON pc.category_id = c.id
             LEFT JOIN pages p ON p.id = pc.page_id AND p.status = \'published\'
             WHERE c.locale = ?
             GROUP BY c.id, c.slug, c.name, c.parent_id
             ORDER BY c.sort_order, c.name'
        );
        $stmt->execute([$context->locale]);
        return ['categories' => $stmt->fetchAll()];
    }
}
