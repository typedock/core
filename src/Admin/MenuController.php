<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class MenuController extends BaseAdminController
{
    public function index(): void
    {
        $stmt  = \Flight::db()->query('SELECT * FROM menus ORDER BY location');
        $menus = $stmt ? $stmt->fetchAll() : [];
        $this->render('pages/menus/index.latte', [
            'menus'         => $menus,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function store(): void
    {
        $pdo = \Flight::db();
        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO menus (id, name, location, locale, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            trim($_POST['name'] ?? ''),
            trim($_POST['location'] ?? ''),
            trim($_POST['locale'] ?? 'en'),
            $now, $now,
        ]);

        $this->redirect('/admin/menus', 'Menu created successfully.');
    }

    public function edit(string $id): void
    {
        $stmt = \Flight::db()->prepare('SELECT * FROM menus WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $menu = $stmt->fetch();

        $stmt = \Flight::db()->prepare('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order');
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();

        $this->render('pages/menus/edit.latte', [
            'menu'          => $menu,
            'items'         => $items,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function update(string $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        \Flight::db()->prepare('UPDATE menus SET name = ?, updated_at = ? WHERE id = ?')
            ->execute([trim($_POST['name'] ?? ''), $now, $id]);
        $this->redirect('/admin/menus', 'Menu updated successfully.');
    }

    public function destroy(string $id): void
    {
        \Flight::db()->prepare('DELETE FROM menus WHERE id = ?')->execute([$id]);
        $this->redirect('/admin/menus', 'Menu deleted successfully.');
    }

    public function storeItem(string $menuId): void
    {
        $pdo  = \Flight::db();
        $id   = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare('SELECT MAX(sort_order) FROM menu_items WHERE menu_id = ?');
        $stmt->execute([$menuId]);
        $maxOrder = (int) $stmt->fetchColumn();

        $pdo->prepare(
            'INSERT INTO menu_items (id, menu_id, label, url, type, target, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $id,
            $menuId,
            trim($_POST['label'] ?? ''),
            trim($_POST['url'] ?? ''),
            $_POST['type'] ?? 'custom',
            $_POST['target'] ?? '_self',
            $maxOrder + 1,
            $now, $now,
        ]);

        $this->redirect('/admin/menus/' . $menuId . '/edit', 'Menu item added successfully.');
    }

    public function destroyItem(string $menuId, string $itemId): void
    {
        \Flight::db()->prepare('DELETE FROM menu_items WHERE id = ? AND menu_id = ?')
            ->execute([$itemId, $menuId]);
        $this->redirect('/admin/menus/' . $menuId . '/edit', 'Menu item deleted successfully.');
    }
}
