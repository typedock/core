<?php
declare(strict_types=1);

namespace TypeDock\Content;

use TypeDock\Core\Queue\Job;
use TypeDock\Core\Queue\JobHandler;

/**
 * Publishes posts whose `scheduled_at` has come around.
 *
 * Until this existed, `scheduled_at` was written by the editor and read by
 * nobody: a post left in `scheduled` status stayed unpublished forever. This
 * is the recurring job that makes the column mean something.
 */
final class PublishScheduledPostsJob implements JobHandler
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function handle(Job $job): void
    {
        $this->publishDue();
    }

    /**
     * Flip every due post to published in one statement, and returns how many
     * moved. Idempotent by construction: the `status = 'scheduled'` predicate
     * excludes anything a previous (or concurrent) run already published.
     *
     * `scheduled_at` is kept rather than cleared so the editor can still see
     * when the post was set to go out.
     */
    public function publishDue(?\DateTimeImmutable $now = null): int
    {
        $ts = ($now ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            "UPDATE posts
                SET status = ?,
                    published_at = COALESCE(published_at, scheduled_at),
                    updated_at = ?
              WHERE status = ?
                AND scheduled_at IS NOT NULL
                AND scheduled_at <= ?"
        );
        $stmt->execute([PostService::STATUS_PUBLISHED, $ts, PostService::STATUS_SCHEDULED, $ts]);

        return $stmt->rowCount();
    }
}
