<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Content\PageService;
use TypeDock\Seo\SeoService;

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
            'theme_layouts'       => $this->themeLayouts(),
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
            'layout'    => trim((string) ($_POST['layout'] ?? '')) ?: null,
        ];

        $page = $this->service()->create($data);
        (new SeoService(\Flight::db()))->upsert('page', $page['id'], $this->collectSeoInput());
        $this->redirect(
            '/admin/pages/' . $page['id'] . '/edit',
            $this->saveMessage('Page', $page)
        );
    }

    public function edit(string $id): void
    {
        $page = $this->service()->find($id);
        if ($page === null) {
            throw new \TypeDock\Exception\NotFoundException("Page not found: {$id}");
        }
        $seo = (new SeoService(\Flight::db()))->findByTarget('page', $id) ?? [];

        $this->render('pages/posts/edit.latte', [
            'post'                => $page,
            'categories'          => [],
            'selected_categories' => [],
            'selected_tag_names'  => [],
            'form_action'         => '/admin/pages/' . $id . '/edit',
            'public_url'          => $this->publicUrlFor($page),
            'seo'                 => $seo,
            'flash_success'       => $this->getFlash('success'),
            'theme_layouts'       => $this->themeLayouts(),
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
            'layout'  => trim((string) ($_POST['layout'] ?? '')) ?: null,
        ];
        $this->service()->update($id, $data);
        (new SeoService(\Flight::db()))->upsert('page', $id, $this->collectSeoInput());
        $page = $this->service()->find($id);
        $this->redirect(
            '/admin/pages/' . $id . '/edit',
            $this->saveMessage('Page', $page)
        );
    }

    public function destroy(string $id): void
    {
        $this->service()->trash($id);
        $this->redirect('/admin/pages', 'Page moved to trash.');
    }
}
