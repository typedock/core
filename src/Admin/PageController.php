<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Content\CategoryService;
use TypeDock\Content\PostService;
use TypeDock\Content\TagService;
use TypeDock\Seo\SeoService;

class PageController extends BaseAdminController
{
    private function service(): PostService
    {
        return new PostService(\Flight::db());
    }

    public function index(): void
    {
        $result = $this->service()->list(['post_type' => 'page', 'per_page' => 50]);
        $this->render('pages/pages/index.latte', [
            'pages'         => $result['items'],
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $catService = new CategoryService(\Flight::db());
        $this->render('pages/posts/edit.latte', [
            'post'                => null,
            'categories'          => $catService->list(['locale' => typedock_default_locale()]),
            'selected_categories' => [],
            'selected_tag_names'  => [],
            'form_action'         => '/admin/pages/create',
            'theme_layouts'       => $this->themeLayouts(),
            'component_defs'      => $this->editorComponentDefs(),
            'post_type'           => 'page',
        ]);
    }

    public function store(): void
    {
        $user = \Flight::get('current_user');
        $data = $this->collectFormData();
        $data['post_type'] = 'page';
        $data['author_id'] = $user['id'] ?? null;
        $data = $this->downgradeIfCannotPublish($data, 'pages:publish');

        $tagService = new TagService(\Flight::db());
        $tagNames   = array_filter(array_map('trim', explode(',', $_POST['tags_input'] ?? '')));
        $data['tag_ids'] = $tagService->findOrCreateByNames(
            $tagNames,
            (string) ($data['locale'] ?? typedock_default_locale())
        );

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
        $this->authorizeOwnerOrAny($page, 'pages:edit_own', 'pages:edit_any');
        $seo = (new SeoService(\Flight::db()))->findByTarget('page', $id) ?? [];

        $catService  = new CategoryService(\Flight::db());
        $pageService = $this->service();
        $selectedCats = array_column($pageService->getCategories($id), 'id');
        $selectedTags = array_column($pageService->getTags($id), 'name');

        $this->render('pages/posts/edit.latte', [
            'post'                => $page,
            'categories'          => $catService->list(['locale' => (string) ($page['locale'] ?? typedock_default_locale())]),
            'selected_categories' => $selectedCats,
            'selected_tag_names'  => $selectedTags,
            'form_action'         => '/admin/pages/' . $id . '/edit',
            'public_url'          => $this->publicUrlFor($page),
            'seo'                 => $seo,
            'flash_success'       => $this->getFlash('success'),
            'theme_layouts'       => $this->themeLayouts(),
            'component_defs'      => $this->editorComponentDefs(),
            'post_type'           => 'page',
        ]);
    }

    public function update(string $id): void
    {
        $existing = $this->service()->find($id);
        if ($existing === null) {
            throw new \TypeDock\Exception\NotFoundException("Page not found: {$id}");
        }
        $this->authorizeOwnerOrAny($existing, 'pages:edit_own', 'pages:edit_any');

        $data = $this->collectFormData();
        $data = $this->downgradeIfCannotPublish($data, 'pages:publish');

        $tagService = new TagService(\Flight::db());
        $tagNames   = array_filter(array_map('trim', explode(',', $_POST['tags_input'] ?? '')));
        $data['tag_ids'] = $tagService->findOrCreateByNames(
            $tagNames,
            (string) ($existing['locale'] ?? typedock_default_locale())
        );

        $this->service()->update($id, $data);
        (new SeoService(\Flight::db()))->upsert('page', $id, $this->collectSeoInput());
        $page = $this->service()->find($id);
        $this->redirect(
            '/admin/pages/' . $id . '/edit',
            $this->saveMessage('Page', $page)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function collectFormData(): array
    {
        return [
            'title'        => trim($_POST['title'] ?? ''),
            'slug'         => trim($_POST['slug'] ?? ''),
            'body'         => $this->filterUnsafeBlocks($_POST['body'] ?? null),
            'excerpt'      => trim($_POST['excerpt'] ?? '') ?: null,
            'status'       => $_POST['status'] ?? 'draft',
            'published_at' => !empty($_POST['published_at']) ? $_POST['published_at'] : null,
            'category_ids' => $_POST['category_ids'] ?? [],
            'layout'       => trim((string) ($_POST['layout'] ?? '')) ?: null,
        ];
    }

    public function destroy(string $id): void
    {
        // Page deletion is editor/admin-only by matrix — no `pages:delete_own`
        // because pages represent site structure, not personal content.
        if (!$this->can('pages:delete_any')) {
            throw new \TypeDock\Exception\ForbiddenException('Insufficient permissions');
        }
        $this->service()->trash($id);
        $this->redirect('/admin/pages', 'Page moved to trash.');
    }
}
