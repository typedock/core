<?php
declare(strict_types=1);

namespace TypeDock\Content;

class MenuTreeResolver
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Resolve a menu location into a tree of MenuItem objects with URLs
     * already resolved for page/post/category targets.
     *
     * @return array<MenuItem>
     */
    public function resolve(string $location, string $locale = 'en'): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM menus WHERE location = ? AND locale = ? LIMIT 1'
        );
        $stmt->execute([$location, $locale]);
        $menu = $stmt->fetch();
        if ($menu === false) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order ASC'
        );
        $stmt->execute([$menu['id']]);
        $flat = $stmt->fetchAll();

        $flat = $this->resolveUrls($flat);
        return $this->buildTree($flat);
    }

    /**
     * @param  array<array<string, mixed>> $items
     * @return array<array<string, mixed>>
     */
    private function resolveUrls(array $items): array
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
            $stmt = $this->pdo->prepare($sql);
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
     * @return array<MenuItem>
     */
    private function buildTree(array $flat, ?string $parentId = null): array
    {
        $tree = [];
        foreach ($flat as $row) {
            $itemParent = $row['parent_id'];
            if (($itemParent === null && $parentId === null) || $itemParent === $parentId) {
                $children = $this->buildTree($flat, (string) $row['id']);
                $tree[]   = new MenuItem(
                    label: (string) $row['label'],
                    url: (string) ($row['url'] ?? ''),
                    targetType: (string) ($row['target_type'] ?? 'custom'),
                    cssClass: isset($row['css_class']) ? (string) $row['css_class'] : null,
                    children: $children,
                );
            }
        }
        return $tree;
    }
}
