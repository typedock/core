<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class MenuProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $location = (string) ($params['location'] ?? 'primary');
        $pdo      = \Flight::db();

        $stmt = $pdo->prepare(
            'SELECT m.id FROM menus m WHERE m.location = ? AND m.locale = ? LIMIT 1'
        );
        $stmt->execute([$location, $context->locale]);
        $menu = $stmt->fetch();

        if ($menu === false) {
            return ['items' => [], 'menu' => null];
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order ASC'
        );
        $stmt->execute([$menu['id']]);
        $flatItems = $stmt->fetchAll();

        // Build tree
        $items = $this->buildTree($flatItems);

        return ['items' => $items, 'menu' => $menu];
    }

    /**
     * @param  array<array<string, mixed>> $flat
     * @return array<array<string, mixed>>
     */
    private function buildTree(array $flat, ?string $parentId = null): array
    {
        $tree = [];
        foreach ($flat as $item) {
            $itemParent = $item['parent_id'];
            if (($itemParent === null && $parentId === null) || $itemParent === $parentId) {
                $item['children'] = $this->buildTree($flat, (string) $item['id']);
                $tree[]           = $item;
            }
        }
        return $tree;
    }
}
