<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Content\PageService;

class PageController extends BaseAdminController
{
    private function service(): PageService
    {
        return new PageService(\Flight::db());
    }

    public function index(): void
    {
        $result = $this->service()->list(['page_type' => 'page', 'per_page' => 50]);
        $this->render('pages/pages/index.latte', [
            'pages'         => $result['items'],
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $this->render('pages/posts/edit.latte', [
            'post'                => null,
            'categories'          => [],
            'selected_categories' => [],
            'selected_tag_names'  => [],
            'form_action'         => '/admin/pages/create',
        ]);
    }

    public function store(): void
    {
        $user = \Flight::get('current_user');
        $data = [
            'title'     => trim($_POST['title'] ?? ''),
            'slug'      => trim($_POST['slug'] ?? ''),
            'body'      => $_POST['body'] ?? null,
            'excerpt'   => trim($_POST['excerpt'] ?? '') ?: null,
            'status'    => $_POST['status'] ?? 'draft',
            'page_type' => 'page',
            'author_id' => $user['id'] ?? null,
        ];

        $page = $this->service()->create($data);
        $this->redirect('/admin/pages/' . $page['id'] . '/edit', 'Page created successfully.');
    }

    public function edit(string $id): void
    {
        $page = $this->service()->find($id);
        if ($page === null) {
            throw new \TypeDock\Exception\NotFoundException("Page not found: {$id}");
        }
        $this->render('pages/posts/edit.latte', [
            'post'                => $page,
            'categories'          => [],
            'selected_categories' => [],
            'selected_tag_names'  => [],
            'form_action'         => '/admin/pages/' . $id . '/edit',
            'flash_success'       => $this->getFlash('success'),
        ]);
    }

    public function update(string $id): void
    {
        $data = [
            'title'   => trim($_POST['title'] ?? ''),
            'slug'    => trim($_POST['slug'] ?? ''),
            'body'    => $_POST['body'] ?? null,
            'excerpt' => trim($_POST['excerpt'] ?? '') ?: null,
            'status'  => $_POST['status'] ?? 'draft',
        ];
        $this->service()->update($id, $data);
        $this->redirect('/admin/pages/' . $id . '/edit', 'Page updated successfully.');
    }

    public function destroy(string $id): void
    {
        $this->service()->trash($id);
        $this->redirect('/admin/pages', 'Page moved to trash.');
    }
}
