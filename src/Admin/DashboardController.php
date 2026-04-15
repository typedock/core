<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class DashboardController extends BaseAdminController
{
    public function index(): void
    {
        $pdo = \Flight::db();

        $stats = [];
        foreach (['post' => 'posts', 'page' => 'pages'] as $type => $key) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM pages WHERE page_type = ? AND status != 'trash'");
            $stmt->execute([$type]);
            $stats[$key] = (int) $stmt->fetchColumn();
        }

        $stmt = $pdo->query('SELECT COUNT(*) FROM media');
        $stats['media'] = $stmt ? (int) $stmt->fetchColumn() : 0;

        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $stats['users'] = $stmt ? (int) $stmt->fetchColumn() : 0;

        $stmt = $pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.status, p.updated_at, u.name as author_name
             FROM pages p LEFT JOIN users u ON u.id = p.author_id
             WHERE p.page_type = 'post' AND p.status != 'trash'
             ORDER BY p.updated_at DESC LIMIT 10"
        );
        $stmt->execute();
        $recentPosts = $stmt->fetchAll();

        $this->render('pages/dashboard.latte', [
            'stats'        => $stats,
            'recent_posts' => $recentPosts,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }
}
