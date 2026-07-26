<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Content\PostService;
use TypeDock\Core\Queue\JobQueue;

class DashboardController extends BaseAdminController
{
    public function index(): void
    {
        $pdo = \Flight::db();

        $stats = [];
        foreach (['post' => 'posts', 'page' => 'pages'] as $type => $key) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE post_type = ? AND status != 'trash'");
            $stmt->execute([$type]);
            $stats[$key] = (int) $stmt->fetchColumn();
        }

        $stmt = $pdo->query('SELECT COUNT(*) FROM media');
        $stats['media'] = $stmt ? (int) $stmt->fetchColumn() : 0;

        $stmt = $pdo->query('SELECT COUNT(*) FROM users');
        $stats['users'] = $stmt ? (int) $stmt->fetchColumn() : 0;

        $stmt = $pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.status, p.updated_at, u.name as author_name
             FROM posts p LEFT JOIN users u ON u.id = p.author_id
             WHERE p.post_type = 'post' AND p.status != 'trash'
             ORDER BY p.updated_at DESC LIMIT 10"
        );
        $stmt->execute();
        $recentPosts = $stmt->fetchAll();

        $this->render('pages/dashboard.latte', [
            'stats'        => $stats,
            'recent_posts' => $recentPosts,
            'queue'        => $this->queueTrouble($pdo),
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    /**
     * Background work that is late or broken, or null when there is nothing to
     * report.
     *
     * The signal is overdue *work*, not a stale heartbeat: with the browser
     * tick as the default worker, "no worker ran recently" is the normal state
     * of a site nobody is looking at, and warning about it would cry wolf on
     * every login. A post that should have gone out ten minutes ago is a real
     * problem whoever is watching.
     *
     * @return array<string, mixed>|null
     */
    private function queueTrouble(\PDO $pdo): ?array
    {
        try {
            $queue  = new JobQueue($pdo);
            $failed = $queue->failedCount();

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM posts
                  WHERE status = ? AND scheduled_at IS NOT NULL AND scheduled_at <= ?'
            );
            $stmt->execute([
                PostService::STATUS_SCHEDULED,
                (new \DateTimeImmutable('-5 minutes'))->format('Y-m-d H:i:s'),
            ]);
            $overdue = (int) $stmt->fetchColumn();

            if ($failed === 0 && $overdue === 0) {
                return null;
            }

            return [
                'failed'       => $failed,
                'overdue'      => $overdue,
                'heartbeat_at' => $queue->heartbeatAt(),
                'last_failure' => $queue->lastFailure(),
                'cli_path'     => TYPEDOCK_ROOT . '/cli/queue-work.php',
            ];
        } catch (\Throwable) {
            // Most likely the jobs table hasn't been migrated yet. The pending
            // migration nag is the right place to surface that, not here.
            return null;
        }
    }
}
