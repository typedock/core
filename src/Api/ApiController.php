<?php
declare(strict_types=1);

namespace TypeDock\Api;

use TypeDock\Content\PostService;
use TypeDock\Content\TiptapRenderer;
use TypeDock\Content\UnsafeBlockFilter;
use TypeDock\Exception\ValidationException;
use TypeDock\Middleware\AuthMiddleware;

class ApiController
{
    private const MAX_PER_PAGE = 100;

    /**
     * @return array<string, mixed>
     */
    private function authenticate(string $permission): array
    {
        (new AuthMiddleware())->requireApiKey($permission);
        $user = \Flight::get('current_api_user');
        return is_array($user) ? $user : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticateToken(): array
    {
        (new AuthMiddleware())->requireApiKey();
        $user = \Flight::get('current_api_user');
        return is_array($user) ? $user : [];
    }

    public function manifest(): void
    {
        $this->json([
            'data' => [
                'name' => (string) site_option('site.name', config('app.name', 'TypeDock')),
                'url' => (string) config('app.url', ''),
                'version' => (string) config('app.version', '0.8.0'),
                'api_version' => 'v1',
                'max_page_size' => self::MAX_PER_PAGE,
                'endpoints' => [
                    'posts' => '/api/v1/posts',
                    'pages' => '/api/v1/pages',
                    'media' => '/api/v1/media',
                ],
            ],
        ]);
    }

    public function listPosts(): void
    {
        $this->listContent(PostService::TYPE_POST, 'posts');
    }

    public function listPages(): void
    {
        $this->listContent(PostService::TYPE_PAGE, 'pages');
    }

    public function getPost(string $id): void
    {
        $this->getContent($id, PostService::TYPE_POST, 'posts');
    }

    public function getPage(string $id): void
    {
        $this->getContent($id, PostService::TYPE_PAGE, 'pages');
    }

    public function createPost(): void
    {
        $this->createContent(PostService::TYPE_POST, 'posts');
    }

    public function createPage(): void
    {
        $this->createContent(PostService::TYPE_PAGE, 'pages');
    }

    public function updatePost(string $id): void
    {
        $this->updateContent($id, PostService::TYPE_POST, 'posts');
    }

    public function updatePage(string $id): void
    {
        $this->updateContent($id, PostService::TYPE_PAGE, 'pages');
    }

    public function deletePost(string $id): void
    {
        $this->deleteContent($id, PostService::TYPE_POST, 'posts');
    }

    public function deletePage(string $id): void
    {
        $this->deleteContent($id, PostService::TYPE_PAGE, 'pages');
    }

    public function listMedia(): void
    {
        $this->authenticate('media:read');

        $result = \Flight::media_service()->list([
            'page' => $this->page(),
            'per_page' => $this->perPage(40),
            'folder' => $this->queryString('folder'),
            'mime_type' => $this->queryString('mime_type'),
            'search' => $this->queryString('search'),
        ]);

        $this->json([
            'data' => array_map(fn(array $row): array => $this->mediaResource($row), $result['items']),
            'meta' => $this->paginationMeta((int) $result['total'], $this->page(), $this->perPage(40)),
        ]);
    }

    public function getMedia(string $id): void
    {
        $this->authenticate('media:read');
        $media = \Flight::media_service()->find($id);
        if ($media === null) {
            $this->error(404, 'not_found', 'Media not found.');
            return;
        }
        $this->json(['data' => $this->mediaResource($media)]);
    }

    public function uploadMedia(): void
    {
        $apiUser = $this->authenticate('media:upload');

        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            $this->error(400, 'missing_file', 'No file was provided.');
            return;
        }

        try {
            $media = \Flight::media_service()->upload($_FILES['file'], '/', (string) ($apiUser['user_id'] ?? ''));
            $this->json(['data' => $this->mediaResource($media)], 201);
        } catch (ValidationException $e) {
            $this->error(422, 'validation_failed', $e->getMessage(), $e->getErrors());
        }
    }

    public function deleteMedia(string $id): void
    {
        $apiUser = $this->authenticateToken();
        $media = \Flight::media_service()->find($id);
        if ($media === null) {
            $this->error(404, 'not_found', 'Media not found.');
            return;
        }

        if (!$this->canOwnOrAny($apiUser, $media, 'media:delete_own', 'media:manage_any', 'uploaded_by')) {
            $this->error(403, 'forbidden', 'This API key cannot delete that media item.');
            return;
        }

        \Flight::media_service()->delete($id);
        $this->noContent();
    }

    private function listContent(string $postType, string $scopePrefix): void
    {
        $apiUser = $this->authenticate($scopePrefix . ':read');

        $options = [
            'post_type' => $postType,
            'page' => $this->page(),
            'per_page' => $this->perPage(),
            'locale' => $this->queryString('locale'),
            'search' => $this->queryString('search'),
            'order_by' => $this->orderBy(),
        ];

        $status = $this->queryString('status', 'published');
        if ($status !== 'published' && !$this->canApi($apiUser, $scopePrefix . ':edit_any')) {
            $this->error(403, 'forbidden', 'This API key cannot list unpublished ' . $scopePrefix . '.');
            return;
        }
        if ($status !== 'all') {
            $options['status'] = $this->status($status);
        }

        $result = $this->posts()->list(array_filter(
            $options,
            static fn(mixed $value): bool => $value !== null && $value !== ''
        ));

        $this->json([
            'data' => array_map(fn(array $row): array => $this->contentResource($row, false), $result['items']),
            'meta' => $this->paginationMeta((int) $result['total'], (int) $result['page'], (int) $result['per_page']),
        ]);
    }

    private function getContent(string $id, string $postType, string $scopePrefix): void
    {
        $apiUser = $this->authenticate($scopePrefix . ':read');

        $row = $this->posts()->find($id);
        if ($row === null || ($row['post_type'] ?? null) !== $postType) {
            $this->error(404, 'not_found', ucfirst($postType) . ' not found.');
            return;
        }
        if (($row['status'] ?? null) !== PostService::STATUS_PUBLISHED
            && !$this->canOwnOrAny($apiUser, $row, $scopePrefix . ':edit_own', $scopePrefix . ':edit_any')) {
            $this->error(403, 'forbidden', 'This API key cannot read that unpublished ' . $postType . '.');
            return;
        }

        $this->json(['data' => $this->contentResource($row, true)]);
    }

    private function createContent(string $postType, string $scopePrefix): void
    {
        $apiUser = $this->authenticate($scopePrefix . ':create');
        $input = $this->jsonInput();
        if ($input === null) {
            return;
        }

        $data = $this->contentPayload($input, $postType, true);
        $data['author_id'] = (string) ($apiUser['user_id'] ?? '');

        if (($data['status'] ?? null) === PostService::STATUS_PUBLISHED && !$this->canApi($apiUser, $scopePrefix . ':publish')) {
            $this->error(403, 'forbidden', 'This API key cannot publish ' . $scopePrefix . '.');
            return;
        }

        try {
            $created = $this->posts()->create($data);
            $this->json(['data' => $this->contentResource($created, true)], 201);
        } catch (ValidationException $e) {
            $this->error(422, 'validation_failed', $e->getMessage(), $e->getErrors());
        }
    }

    private function updateContent(string $id, string $postType, string $scopePrefix): void
    {
        $apiUser = $this->authenticateToken();
        $existing = $this->posts()->find($id);
        if ($existing === null || ($existing['post_type'] ?? null) !== $postType) {
            $this->error(404, 'not_found', ucfirst($postType) . ' not found.');
            return;
        }
        if (!$this->canOwnOrAny($apiUser, $existing, $scopePrefix . ':edit_own', $scopePrefix . ':edit_any')) {
            $this->error(403, 'forbidden', 'This API key cannot edit that ' . $postType . '.');
            return;
        }

        $input = $this->jsonInput();
        if ($input === null) {
            return;
        }
        $data = $this->contentPayload($input, $postType, false);

        if (($data['status'] ?? null) === PostService::STATUS_PUBLISHED && !$this->canApi($apiUser, $scopePrefix . ':publish')) {
            $this->error(403, 'forbidden', 'This API key cannot publish ' . $scopePrefix . '.');
            return;
        }

        try {
            $updated = $this->posts()->update($id, $data);
            $this->json(['data' => $this->contentResource($updated, true)]);
        } catch (ValidationException $e) {
            $this->error(422, 'validation_failed', $e->getMessage(), $e->getErrors());
        }
    }

    private function deleteContent(string $id, string $postType, string $scopePrefix): void
    {
        $apiUser = $this->authenticateToken();
        $existing = $this->posts()->find($id);
        if ($existing === null || ($existing['post_type'] ?? null) !== $postType) {
            $this->error(404, 'not_found', ucfirst($postType) . ' not found.');
            return;
        }
        if (!$this->canOwnOrAny($apiUser, $existing, $scopePrefix . ':delete_own', $scopePrefix . ':delete_any')) {
            $this->error(403, 'forbidden', 'This API key cannot delete that ' . $postType . '.');
            return;
        }
        $this->posts()->trash($id);
        $this->noContent();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function contentPayload(array $input, string $postType, bool $isCreate): array
    {
        $payload = [
            'post_type' => $postType,
        ];

        foreach (['title', 'slug', 'excerpt', 'status', 'published_at', 'scheduled_at', 'layout', 'template', 'locale'] as $key) {
            if (array_key_exists($key, $input)) {
                $payload[$key] = is_string($input[$key]) ? trim($input[$key]) : $input[$key];
            }
        }

        if ($isCreate && !isset($payload['title'])) {
            $payload['title'] = '';
        }
        if (isset($payload['status'])) {
            $payload['status'] = $this->status((string) $payload['status']);
        }
        if (array_key_exists('body', $input)) {
            $payload['body'] = $this->filterBody($input['body']);
        }
        if (array_key_exists('category_ids', $input) && is_array($input['category_ids'])) {
            $payload['category_ids'] = array_values(array_filter($input['category_ids'], 'is_string'));
        }
        if (array_key_exists('tag_ids', $input) && is_array($input['tag_ids'])) {
            $payload['tag_ids'] = array_values(array_filter($input['tag_ids'], 'is_string'));
        }

        return $payload;
    }

    private function filterBody(mixed $body): mixed
    {
        if (!is_string($body) && !is_array($body) && $body !== null) {
            return null;
        }
        try {
            $apiUser = \Flight::get('current_api_user');
            $apiUser = is_array($apiUser) ? $apiUser : [];
            $filter = new UnsafeBlockFilter(
                \Flight::components(),
                fn(string $permission): bool => $this->canApi($apiUser, $permission)
            );
            return $filter->filter($body);
        } catch (\Throwable) {
            return $body;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonInput(): ?array
    {
        $raw = file_get_contents('php://input') ?: '';
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->error(400, 'invalid_json', 'Request body must be a JSON object.');
            return null;
        }
        return $decoded;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function contentResource(array $row, bool $includeBody): array
    {
        $body = $this->decodeJson($row['body'] ?? null);
        $slug = (string) ($row['slug'] ?? '');
        $type = (string) ($row['post_type'] ?? 'post');

        $resource = [
            'id' => (string) $row['id'],
            'type' => $type,
            'slug' => $slug,
            'title' => (string) ($row['title'] ?? ''),
            'excerpt' => PostService::excerptFromRow($row),
            'status' => (string) ($row['status'] ?? ''),
            'locale' => (string) ($row['locale'] ?? 'en'),
            'author' => [
                'id' => $row['author_id'] ?? null,
                'name' => $row['author_name'] ?? null,
            ],
            'url' => $type === 'page' ? '/' . ltrim($slug, '/') : post_path($slug),
            'published_at' => $row['published_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];

        if ($includeBody) {
            $resource['body'] = $body;
            $resource['plain_text'] = TiptapRenderer::toPlainText($row['body'] ?? null);
        }

        return $resource;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mediaResource(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'url' => (string) ($row['url'] ?? ''),
            'path' => (string) ($row['path'] ?? ''),
            'filename' => (string) ($row['original_filename'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'file_size' => isset($row['file_size']) ? (int) $row['file_size'] : null,
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'alt_text' => $row['alt_text'] ?? null,
            'caption' => $row['caption'] ?? null,
            'folder' => (string) ($row['folder'] ?? '/'),
            'thumbnails' => $this->decodeJson($row['thumbnails'] ?? null) ?? [],
            'uploaded_by' => $row['uploaded_by'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    private function posts(): PostService
    {
        return new PostService(\Flight::db());
    }

    /**
     * @param array<string, mixed> $apiUser
     */
    private function canApi(array $apiUser, string $permission): bool
    {
        return \Flight::apikey()->can($apiUser, $permission);
    }

    /**
     * @param array<string, mixed> $apiUser
     * @param array<string, mixed> $row
     */
    private function canOwnOrAny(array $apiUser, array $row, string $ownPermission, string $anyPermission, string $ownerColumn = 'author_id'): bool
    {
        if ($this->canApi($apiUser, $anyPermission)) {
            return true;
        }
        $owner = $row[$ownerColumn] ?? null;
        return $owner !== null
            && (string) $owner === (string) ($apiUser['user_id'] ?? '')
            && $this->canApi($apiUser, $ownPermission);
    }

    private function page(): int
    {
        return max(1, (int) ($_GET['page'] ?? 1));
    }

    private function perPage(int $default = 20): int
    {
        return max(1, min((int) ($_GET['per_page'] ?? $default), self::MAX_PER_PAGE));
    }

    private function queryString(string $key, ?string $default = null): ?string
    {
        $value = isset($_GET[$key]) ? trim((string) $_GET[$key]) : $default;
        return $value !== '' ? $value : null;
    }

    private function orderBy(): string
    {
        $value = (string) ($_GET['order_by'] ?? 'updated_at');
        return in_array($value, ['updated_at', 'published_at', 'title'], true) ? $value : 'updated_at';
    }

    private function status(?string $status): string
    {
        $status = $status ?? PostService::STATUS_PUBLISHED;
        return in_array($status, [
            PostService::STATUS_DRAFT,
            PostService::STATUS_REVIEW,
            PostService::STATUS_SCHEDULED,
            PostService::STATUS_PUBLISHED,
            PostService::STATUS_TRASH,
        ], true) ? $status : PostService::STATUS_PUBLISHED;
    }

    /**
     * @return mixed
     */
    private function decodeJson(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * @return array<string, int>
     */
    private function paginationMeta(int $total, int $page, int $perPage): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'page_count' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $details
     */
    private function error(int $status, string $code, string $message, array $details = []): void
    {
        $payload = [
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
        if ($details !== []) {
            $payload['error']['details'] = $details;
        }
        $this->json($payload, $status);
    }

    private function noContent(): void
    {
        http_response_code(204);
    }
}
