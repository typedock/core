<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Content\PostService;
use TypeDock\Content\CategoryService;
use TypeDock\Content\TagService;
use TypeDock\Seo\SeoService;

class PostController extends BaseAdminController
{
    private function service(): PostService
    {
        return new PostService(\Flight::db());
    }

    public function index(): void
    {
        $filters = [
            'status' => $_GET['status'] ?? '',
            'search' => $_GET['search'] ?? '',
        ];

        $options = [
            'post_type' => 'post',
            'page'      => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page'  => 20,
        ];

        if ($filters['status'] !== '') {
            $options['status'] = $filters['status'];
        }
        if ($filters['search'] !== '') {
            $options['search'] = $filters['search'];
        }

        $result = $this->service()->list($options);

        $this->render('pages/posts/index.latte', [
            'posts'        => $result['items'],
            'total'        => $result['total'],
            'current_page' => $options['page'],
            'per_page'     => $options['per_page'],
            'filters'      => $filters,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function create(): void
    {
        $catService = new CategoryService(\Flight::db());
        $this->render('pages/posts/edit.latte', [
            'post'                => null,
            'categories'          => $catService->list(),
            'selected_categories' => [],
            'selected_tag_names'  => [],
            'form_action'         => '/admin/posts/create',
            'theme_layouts'       => $this->themeLayouts(),
            'component_defs'      => $this->editorComponentDefs(),
            'post_type'           => 'post',
        ]);
    }

    public function store(): void
    {
        $user = \Flight::get('current_user');
        $data = $this->getPostData();
        $data['author_id'] = $user['id'] ?? null;
        $data['post_type'] = 'post';
        $data = $this->downgradeIfCannotPublish($data, 'posts:publish');

        $tagService = new TagService(\Flight::db());
        $tagNames   = array_filter(array_map('trim', explode(',', $_POST['tags_input'] ?? '')));
        $data['tag_ids'] = $tagService->findOrCreateByNames($tagNames);

        try {
            $page = $this->service()->create($data);
            (new SeoService(\Flight::db()))->upsert('post', $page['id'], $this->collectSeoInput());
            $this->redirect(
                '/admin/posts/' . $page['id'] . '/edit',
                $this->saveMessage('Post', $page)
            );
        } catch (\TypeDock\Exception\ValidationException $e) {
            $this->renderEditWithErrors($e->getErrors(), null);
        }
    }

    public function edit(string $id): void
    {
        $page = $this->service()->find($id);
        if ($page === null) {
            throw new \TypeDock\Exception\NotFoundException("Post not found: {$id}");
        }
        $this->authorizeOwnerOrAny($page, 'posts:edit_own', 'posts:edit_any');

        $catService  = new CategoryService(\Flight::db());
        $tagService  = new TagService(\Flight::db());
        $pageService = $this->service();

        $selectedCats = array_column($pageService->getCategories($id), 'id');
        $selectedTags = array_column($pageService->getTags($id), 'name');

        $seo = (new SeoService(\Flight::db()))->findByTarget('post', $id) ?? [];

        $this->render('pages/posts/edit.latte', [
            'post'                => $page,
            'categories'          => $catService->list(),
            'selected_categories' => $selectedCats,
            'selected_tag_names'  => $selectedTags,
            'form_action'         => '/admin/posts/' . $id . '/edit',
            'public_url'          => $this->publicUrlFor($page),
            'seo'                 => $seo,
            'flash_success'       => $this->getFlash('success'),
            'theme_layouts'       => $this->themeLayouts(),
            'component_defs'      => $this->editorComponentDefs(),
            'post_type'           => 'post',
        ]);
    }

    public function update(string $id): void
    {
        $existing = $this->service()->find($id);
        if ($existing === null) {
            throw new \TypeDock\Exception\NotFoundException("Post not found: {$id}");
        }
        $this->authorizeOwnerOrAny($existing, 'posts:edit_own', 'posts:edit_any');

        $data = $this->getPostData();
        $data = $this->downgradeIfCannotPublish($data, 'posts:publish');

        // Handle tags
        $tagService = new TagService(\Flight::db());
        $tagNames   = array_filter(array_map('trim', explode(',', $_POST['tags_input'] ?? '')));
        $data['tag_ids'] = $tagService->findOrCreateByNames($tagNames);

        try {
            $this->service()->update($id, $data);
            (new SeoService(\Flight::db()))->upsert('post', $id, $this->collectSeoInput());
            $page = $this->service()->find($id);
            $this->redirect(
                '/admin/posts/' . $id . '/edit',
                $this->saveMessage('Post', $page)
            );
        } catch (\TypeDock\Exception\ValidationException $e) {
            $page = $this->service()->find($id);
            $this->renderEditWithErrors($e->getErrors(), $page);
        }
    }

    public function destroy(string $id): void
    {
        $existing = $this->service()->find($id);
        if ($existing === null) {
            throw new \TypeDock\Exception\NotFoundException("Post not found: {$id}");
        }
        $this->authorizeOwnerOrAny($existing, 'posts:delete_own', 'posts:delete_any');

        $this->service()->trash($id);
        $this->redirect('/admin/posts', 'Post moved to trash.');
    }

    public function autosave(string $id): void
    {
        $existing = $this->service()->find($id);
        if ($existing === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            return;
        }
        // The autosave endpoint is shared between post and page editors; use
        // the row's own post_type to pick the correct permission namespace.
        $prefix = ($existing['post_type'] ?? 'post') === 'page' ? 'pages' : 'posts';
        try {
            $this->authorizeOwnerOrAny($existing, "{$prefix}:edit_own", "{$prefix}:edit_any");
        } catch (\TypeDock\Exception\ForbiddenException $e) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $data = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];

        // Use the registry+filter directly so we can return the removed-block
        // list in the JSON response instead of pushing a flash message that
        // would only surface on the next full page render.
        $registry = \Flight::components();
        $filter   = new \TypeDock\Content\UnsafeBlockFilter(
            $registry,
            fn(string $perm): bool => $this->can($perm),
        );
        $body = $filter->filter($data['body'] ?? null);

        try {
            $this->service()->update($id, [
                'title' => $data['title'] ?? null,
                'body'  => $body,
            ]);
            header('Content-Type: application/json');
            echo json_encode([
                'ok'       => true,
                'saved_at' => date('H:i:s'),
                'removed_blocks' => $filter->getRemoved(),
            ]);
        } catch (\Throwable $e) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getPostData(): array
    {
        return [
            'title'        => trim($_POST['title'] ?? ''),
            'slug'         => trim($_POST['slug'] ?? ''),
            'body'         => $this->filterUnsafeBlocks($_POST['body'] ?? null),
            'excerpt'      => trim($_POST['excerpt'] ?? '') ?: null,
            'status'       => $_POST['status'] ?? 'draft',
            'post_type'    => $_POST['post_type'] ?? 'post',
            'published_at' => !empty($_POST['published_at']) ? $_POST['published_at'] : null,
            'category_ids' => $_POST['category_ids'] ?? [],
            'layout'       => trim((string) ($_POST['layout'] ?? '')) ?: null,
        ];
    }

    /**
     * @param array<string, string[]> $errors
     * @param array<string, mixed>|null $post
     */
    private function renderEditWithErrors(array $errors, ?array $post): void
    {
        $catService = new CategoryService(\Flight::db());
        $this->render('pages/posts/edit.latte', [
            'post'                => $post,
            'categories'          => $catService->list(),
            'selected_categories' => $_POST['category_ids'] ?? [],
            'selected_tag_names'  => array_filter(array_map('trim', explode(',', $_POST['tags_input'] ?? ''))),
            'form_action'         => $post ? '/admin/posts/' . $post['id'] . '/edit' : '/admin/posts/create',
            'public_url'          => $this->publicUrlFor($post),
            'seo'                 => $this->collectSeoInput(),
            'errors'              => $errors,
            'theme_layouts'       => $this->themeLayouts(),
            'component_defs'      => $this->editorComponentDefs(),
            'post_type'           => 'post',
        ]);
    }
}
