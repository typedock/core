<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Content\CategoryService;

class CategoryController extends BaseAdminController
{
    public function index(): void
    {
        $service = new CategoryService(\Flight::db());
        $this->render('pages/categories/index.latte', [
            'categories'    => $service->list(),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function store(): void
    {
        $service = new CategoryService(\Flight::db());
        $service->create([
            'name'        => trim($_POST['name'] ?? ''),
            'slug'        => trim($_POST['slug'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'parent_id'   => !empty($_POST['parent_id']) ? $_POST['parent_id'] : null,
            'locale'      => $_POST['locale'] ?? 'en',
        ]);
        $this->redirect('/admin/categories', 'Category created successfully.');
    }

    public function destroy(string $id): void
    {
        $service = new CategoryService(\Flight::db());
        $service->delete($id);
        $this->redirect('/admin/categories', 'Category deleted successfully.');
    }
}
