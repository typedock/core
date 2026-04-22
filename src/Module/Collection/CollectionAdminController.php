<?php
declare(strict_types=1);

namespace TypeDock\Module\Collection;

use TypeDock\Admin\BaseAdminController;
use TypeDock\Exception\ValidationException;

class CollectionAdminController extends BaseAdminController
{
    private function service(): CollectionService
    {
        return \Flight::collections();
    }

    public function index(): void
    {
        $this->render('pages/collections/index.latte', [
            'collections'   => $this->service()->listCollections(),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function store(): void
    {
        try {
            $this->service()->createCollection([
                'handle'      => $_POST['handle'] ?? '',
                'name'        => $_POST['name'] ?? '',
                'description' => $_POST['description'] ?? null,
            ]);
            $this->redirect('/admin/collections', 'Collection created.');
        } catch (ValidationException $e) {
            $this->redirect('/admin/collections', $e->getMessage(), 'error');
        }
    }

    public function destroy(string $id): void
    {
        $this->service()->deleteCollection($id);
        $this->redirect('/admin/collections', 'Collection deleted.');
    }

    public function items(string $id): void
    {
        $col = $this->service()->findCollection($id);
        if ($col === null) {
            $this->redirect('/admin/collections', 'Collection not found.', 'error');
            return;
        }
        $list = $this->service()->listItems($id);
        $this->render('pages/collections/items.latte', [
            'collection'    => $col,
            'items'         => $list['items'],
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function storeItem(string $id): void
    {
        try {
            $this->service()->createItem($id, [
                'title' => $_POST['title'] ?? '',
                'slug'  => $_POST['slug'] ?? '',
                'data'  => isset($_POST['data']) && is_string($_POST['data'])
                    ? (json_decode($_POST['data'], true) ?: [])
                    : [],
            ]);
            $this->redirect('/admin/collections/' . $id . '/items', 'Item created.');
        } catch (\Throwable $e) {
            $this->redirect('/admin/collections/' . $id . '/items', $e->getMessage(), 'error');
        }
    }

    public function destroyItem(string $id, string $itemId): void
    {
        $this->service()->deleteItem($itemId);
        $this->redirect('/admin/collections/' . $id . '/items', 'Item deleted.');
    }
}
