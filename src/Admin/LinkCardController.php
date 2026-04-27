<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class LinkCardController
{
    public function resolve(): void
    {
        $url = trim($_GET['url'] ?? '');
        if ($url === '' || !str_starts_with($url, '/')) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Internal URL required']);
            return;
        }

        $slug = ltrim($url, '/');
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare(
            "SELECT title, excerpt, slug FROM posts WHERE slug = ? AND status = 'published' LIMIT 1"
        );
        $stmt->execute([$slug]);
        $page = $stmt->fetch();

        if ($page === false) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Page not found']);
            return;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'title'   => $page['title'],
            'excerpt' => $page['excerpt'],
            'url'     => config('app.url') . '/' . $page['slug'],
        ]);
    }
}
