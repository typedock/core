<?php
declare(strict_types=1);

namespace TypeDock\Api;

use TypeDock\Middleware\AuthMiddleware;

class ApiController
{
    private function authenticate(): void
    {
        (new AuthMiddleware())->requireApiKey();
    }

    public function listPosts(): void
    {
        $this->authenticate();

        $pdo    = \Flight::db();
        $status = $_GET['status'] ?? 'published';
        $limit  = min((int) ($_GET['limit'] ?? 20), 100);
        $offset = (int) ($_GET['offset'] ?? 0);

        $stmt = $pdo->prepare(
            "SELECT id, slug, title, excerpt, post_type, status, published_at, created_at, updated_at
             FROM posts WHERE status = ? ORDER BY updated_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$status, $limit, $offset]);
        $posts = $stmt->fetchAll();

        $this->json(['data' => $posts]);
    }

    public function getPost(string $id): void
    {
        $this->authenticate();

        $pdo  = \Flight::db();
        $stmt = $pdo->prepare('SELECT * FROM posts WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $post = $stmt->fetch();

        if ($post === false) {
            $this->json(['error' => 'Not found'], 404);
            return;
        }

        $this->json(['data' => $post]);
    }

    public function listMedia(): void
    {
        $this->authenticate();

        $pdo    = \Flight::db();
        $limit  = min((int) ($_GET['limit'] ?? 20), 100);
        $offset = (int) ($_GET['offset'] ?? 0);

        $stmt = $pdo->prepare('SELECT * FROM media ORDER BY created_at DESC LIMIT ? OFFSET ?');
        $stmt->execute([$limit, $offset]);
        $media = $stmt->fetchAll();

        $this->json(['data' => $media]);
    }

    public function uploadMedia(): void
    {
        $this->authenticate();

        $apiUser = \Flight::get('current_api_user');

        if (empty($_FILES['file'])) {
            $this->json(['error' => 'No file provided'], 400);
            return;
        }

        try {
            $service = new \TypeDock\Media\MediaService(\Flight::db(), \Flight::storage());
            $media   = $service->upload($_FILES['file'], '/', $apiUser['user_id'] ?? null);
            $this->json(['data' => $media], 201);
        } catch (\TypeDock\Exception\ValidationException $e) {
            $this->json(['error' => $e->getMessage(), 'details' => $e->getErrors()], 422);
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
