<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class MenuProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $location = (string) ($params['location'] ?? 'header');
        $pdo      = \Flight::db();

        // Locale is stored but not surfaced in the admin UI yet — pin to 'en'
        // so a site whose app.locale is set to e.g. 'ja' still resolves the
        // single menu row that admin writes.
        $stmt = $pdo->prepare(
            "SELECT m.id FROM menus m WHERE m.location = ? AND m.locale = 'en' LIMIT 1"
        );
        $stmt->execute([$location]);
        $menu = $stmt->fetch();

        if ($menu === false) {
            return ['items' => [], 'menu' => null];
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order ASC'
        );
        $stmt->execute([$menu['id']]);
        $flatItems = $stmt->fetchAll();

        $flatItems = $this->resolveUrls($pdo, $flatItems);
        $items     = $this->buildTree($flatItems);

        return ['items' => $items, 'menu' => $menu];
    }

    /**
     * Batch-resolve URLs for items referencing a Page/Post/Category by id.
     * Custom items keep their stored url verbatim.
     *
     * @param  array<array<string, mixed>> $items
     * @return array<array<string, mixed>>
     */
    private function resolveUrls(\PDO $pdo, array $items): array
    {
        $idsByType = ['page' => [], 'post' => [], 'category' => []];
        foreach ($items as $i) {
            $type = $i['target_type'] ?? null;
            $tid  = $i['target_id'] ?? null;
            if ($tid && isset($idsByType[$type])) {
                $idsByType[$type][] = $tid;
            }
        }

        $slugMap = ['page' => [], 'post' => [], 'category' => []];
        foreach (['page' => 'posts', 'post' => 'posts', 'category' => 'categories'] as $type => $table) {
            $ids = array_values(array_unique($idsByType[$type]));
            if ($ids === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($table === 'posts') {
                $sql  = "SELECT id, slug FROM posts WHERE post_type = ? AND status = 'published' AND id IN ($placeholders)";
                $args = array_merge([$type], $ids);
            } else {
                $sql  = "SELECT id, slug FROM categories WHERE id IN ($placeholders)";
                $args = $ids;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            foreach ($stmt->fetchAll() as $row) {
                $slugMap[$type][$row['id']] = $row['slug'];
            }
        }

        foreach ($items as &$item) {
            $type = $item['target_type'] ?? 'custom';
            $tid  = $item['target_id'] ?? null;
            if ($type === 'custom' || !$tid) {
                continue;
            }
            $slug = $slugMap[$type][$tid] ?? null;
            if ($slug === null) {
                // Target was deleted or unpublished — fall back to stored url (likely null).
                continue;
            }
            $item['url'] = match ($type) {
                'page'     => '/' . ltrim($slug, '/'),
                'post'     => post_path($slug),
                'category' => '/category/' . $slug,
                default    => $item['url'],
            };
        }
        unset($item);

        return $items;
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
