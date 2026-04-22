<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Theme\ThemeLoader;

class MenuController extends BaseAdminController
{
    // Menus are bound to theme-declared locations only — locale is stored as
    // a constant until a first-class multilingual feature lands, at which
    // point the UI can surface it.
    private const LOCALE = 'en';

    public function index(): void
    {
        $locations = $this->loadLocations();
        $pdo       = \Flight::db();

        $counts = [];
        foreach (array_keys($locations) as $key) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM menu_items mi
                 JOIN menus m ON m.id = mi.menu_id
                 WHERE m.location = ? AND m.locale = ?'
            );
            $stmt->execute([$key, self::LOCALE]);
            $counts[$key] = (int) $stmt->fetchColumn();
        }

        $this->render('pages/menus/index.latte', [
            'locations'     => $locations,
            'counts'        => $counts,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function edit(string $location): void
    {
        $locations = $this->loadLocations();
        if (!isset($locations[$location])) {
            $this->redirect('/admin/menus', 'That menu location is not declared by the active theme.');
            return;
        }

        $pdo  = \Flight::db();
        $menu = $this->ensureMenu($pdo, $location, $locations[$location]['label'] ?? $location);

        $stmt = $pdo->prepare('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order');
        $stmt->execute([$menu['id']]);
        $items = $stmt->fetchAll();

        $pages = $pdo->query(
            "SELECT id, title, slug FROM pages WHERE page_type = 'page' AND status = 'published' ORDER BY title"
        )->fetchAll();
        $posts = $pdo->query(
            "SELECT id, title, slug FROM pages WHERE page_type = 'post' AND status = 'published' ORDER BY title"
        )->fetchAll();
        $categories = $pdo->query('SELECT id, name, slug FROM categories ORDER BY name')->fetchAll();

        $parentCandidates = array_values(array_filter($items, fn($i) => $i['parent_id'] === null));
        $itemTree         = $this->buildItemTree($items);

        $this->render('pages/menus/edit.latte', [
            'location'          => $location,
            'location_meta'     => $locations[$location],
            'locations'         => $locations,
            'menu'              => $menu,
            'items'             => $items,
            'item_tree'         => $itemTree,
            'pages'             => $pages,
            'posts'             => $posts,
            'categories'        => $categories,
            'parent_candidates' => $parentCandidates,
            'flash_success'     => $this->getFlash('success'),
            'flash_error'       => $this->getFlash('error'),
        ]);
    }

    public function storeItem(string $location): void
    {
        $locations = $this->loadLocations();
        if (!isset($locations[$location])) {
            $this->redirect('/admin/menus', 'That menu location is not declared by the active theme.');
            return;
        }

        $pdo  = \Flight::db();
        $menu = $this->ensureMenu($pdo, $location, $locations[$location]['label'] ?? $location);

        $stmt = $pdo->prepare('SELECT MAX(sort_order) FROM menu_items WHERE menu_id = ?');
        $stmt->execute([$menu['id']]);
        $maxOrder = (int) $stmt->fetchColumn();

        $targetType = $_POST['target_type'] ?? 'custom';
        $targetId   = null;
        $url        = null;

        if ($targetType === 'custom') {
            $url = trim($_POST['url'] ?? '');
        } else {
            $targetId = $_POST['target_id_' . $targetType] ?? null;
            if ($targetId === '' || $targetId === null) {
                $this->redirect('/admin/menus/' . $location, 'Please pick a target for the selected type.');
                return;
            }
        }

        $parentId = $_POST['parent_id'] ?? '';
        $parentId = $parentId === '' ? null : $parentId;

        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO menu_items (id, menu_id, parent_id, label, url, target_type, target_id, sort_order, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $menu['id'],
            $parentId,
            trim($_POST['label'] ?? ''),
            $url,
            $targetType,
            $targetId,
            $maxOrder + 1,
            $now,
        ]);

        $this->redirect('/admin/menus/' . $location, 'Menu item added successfully.');
    }

    public function destroyItem(string $location, string $itemId): void
    {
        $pdo  = \Flight::db();
        $menu = $this->findMenu($pdo, $location);
        if ($menu !== null) {
            $pdo->prepare('DELETE FROM menu_items WHERE id = ? AND menu_id = ?')
                ->execute([$itemId, $menu['id']]);
        }
        $this->redirect('/admin/menus/' . $location, 'Menu item deleted successfully.');
    }

    /**
     * Read the active theme's menu-location declarations from theme.json.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadLocations(): array
    {
        $loader      = new ThemeLoader();
        $activeTheme = $loader->resolveActiveTheme(\Flight::db());
        $config      = $loader->loadThemeConfig($activeTheme);
        $menus       = $config['menus'] ?? [];
        return is_array($menus) ? $menus : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findMenu(\PDO $pdo, string $location): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM menus WHERE location = ? AND locale = ? LIMIT 1');
        $stmt->execute([$location, self::LOCALE]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureMenu(\PDO $pdo, string $location, string $name): array
    {
        $existing = $this->findMenu($pdo, $location);
        if ($existing !== null) {
            return $existing;
        }

        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO menus (id, name, location, locale, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$id, $name, $location, self::LOCALE, $now, $now]);

        return (array) $this->findMenu($pdo, $location);
    }

    /**
     * @param  array<array<string, mixed>> $flat
     * @return array<array<string, mixed>>
     */
    private function buildItemTree(array $flat, ?string $parentId = null): array
    {
        $tree = [];
        foreach ($flat as $item) {
            if (($item['parent_id'] ?? null) === $parentId) {
                $item['children'] = $this->buildItemTree($flat, (string) $item['id']);
                $tree[]           = $item;
            }
        }
        return $tree;
    }
}
