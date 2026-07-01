<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Content\TagService;

class TagController extends BaseAdminController
{
    public function index(): void
    {
        $service = new TagService(\Flight::db());
        $this->render('pages/tags/index.latte', [
            'tags'          => $service->list(['locale' => typedock_default_locale()]),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function store(): void
    {
        $service = new TagService(\Flight::db());
        $service->create([
            'name'   => trim($_POST['name'] ?? ''),
            'slug'   => trim($_POST['slug'] ?? ''),
            'locale' => $_POST['locale'] ?? typedock_default_locale(),
        ]);
        $this->redirect('/admin/tags', 'Tag created successfully.');
    }

    public function destroy(string $id): void
    {
        $service = new TagService(\Flight::db());
        $service->delete($id);
        $this->redirect('/admin/tags', 'Tag deleted successfully.');
    }
}
