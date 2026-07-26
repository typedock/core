<?php
declare(strict_types=1);

namespace TypeDock\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TypeDock\Content\PublishScheduledPostsJob;
use TypeDock\Core\Migration\Migrator;
use TypeDock\Core\Queue\Job;
use TypeDock\Core\Queue\JobHandler;
use TypeDock\Core\Queue\JobQueue;
use TypeDock\Core\Queue\JobRunner;

/**
 * Exercises the queue against a real migrated SQLite database — the driver
 * without SKIP LOCKED, which is exactly why the claim is written the way it is.
 */
final class JobQueueTest extends TestCase
{
    private string $sqlitePath;
    private \PDO $pdo;
    private JobQueue $queue;

    protected function setUp(): void
    {
        $this->sqlitePath = sys_get_temp_dir() . '/typedock-queue-' . bin2hex(random_bytes(6)) . '.sqlite';

        $this->pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $result = (new Migrator($this->pdo, 'sqlite', TYPEDOCK_ROOT . '/migrations'))->migrate();
        $this->assertSame([], $result['errors'], 'Migration errors: ' . json_encode($result['errors']));

        $this->queue = new JobQueue($this->pdo);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
    }

    public function testClaimHandsOutEachJobOnce(): void
    {
        $this->queue->push('test.noop', ['n' => 1]);

        $first = $this->queue->claim('worker-a');
        $this->assertInstanceOf(Job::class, $first);
        $this->assertSame('test.noop', $first->handler);
        $this->assertSame(['n' => 1], $first->payload);
        $this->assertSame(1, $first->attempts);

        $this->assertNull($this->queue->claim('worker-b'), 'A leased job must not be handed to a second worker');
    }

    public function testDelayedJobIsNotClaimableYet(): void
    {
        $this->queue->push('test.noop', [], null, 3600);

        $this->assertNull($this->queue->claim('worker-a'));
    }

    public function testExpiredLeaseIsReclaimedByAnotherWorker(): void
    {
        $this->queue->push('test.noop');
        $job = $this->queue->claim('worker-a');
        $this->assertNotNull($job);

        // Simulate worker-a dying: its lease runs out with the row still running.
        $this->pdo->prepare('UPDATE jobs SET lease_until = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $job->id]);

        $reclaimed = $this->queue->claim('worker-b');
        $this->assertNotNull($reclaimed);
        $this->assertSame($job->id, $reclaimed->id);
        $this->assertSame(2, $reclaimed->attempts, 'Re-claiming counts as another attempt');
    }

    public function testCompletedOneShotJobIsRemoved(): void
    {
        $this->queue->push('test.noop');
        $job = $this->queue->claim('worker-a');
        $this->assertNotNull($job);

        $this->queue->complete($job);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    public function testCompletedRecurringJobIsRearmedForLater(): void
    {
        $this->queue->push('test.tick');
        $job = $this->queue->claim('worker-a');
        $this->assertNotNull($job);

        $this->queue->complete($job, 60);

        $row = $this->pdo->query('SELECT status, attempts, run_after, last_error FROM jobs')->fetch();
        $this->assertSame('pending', $row['status']);
        $this->assertSame(0, (int) $row['attempts'], 'A successful run clears the attempt counter');
        $this->assertGreaterThan((new \DateTimeImmutable())->format('Y-m-d H:i:s'), (string) $row['run_after']);
        $this->assertNull($row['last_error']);
        $this->assertNull($this->queue->claim('worker-b'), 'The re-armed job is not due yet');
    }

    public function testOneShotJobRetriesThenParksAsFailed(): void
    {
        $this->queue->push('test.flaky');

        for ($attempt = 1; $attempt <= JobQueue::MAX_ATTEMPTS; $attempt++) {
            // Backoff would otherwise hold the row back between attempts.
            $this->pdo->exec("UPDATE jobs SET run_after = NULL");

            $job = $this->queue->claim('worker-a');
            $this->assertNotNull($job, "Attempt {$attempt} should be claimable");
            $this->queue->fail($job, 'boom');
        }

        $row = $this->pdo->query('SELECT status, attempts, last_error FROM jobs')->fetch();
        $this->assertSame('failed', $row['status']);
        $this->assertSame(JobQueue::MAX_ATTEMPTS, (int) $row['attempts']);
        $this->assertSame('boom', $row['last_error']);
        $this->assertSame(1, $this->queue->failedCount());
        $this->assertSame(0, $this->queue->dueCount(), 'A parked job is no longer runnable work');
        $this->assertSame('test.flaky', $this->queue->lastFailure()['handler']);
    }

    public function testRecurringJobIsNeverParkedByFailure(): void
    {
        $this->queue->push('test.tick');

        for ($attempt = 1; $attempt <= JobQueue::MAX_ATTEMPTS + 2; $attempt++) {
            $this->pdo->exec('UPDATE jobs SET run_after = NULL');
            $job = $this->queue->claim('worker-a');
            $this->assertNotNull($job);
            $this->queue->fail($job, 'boom', 60);
        }

        $row = $this->pdo->query('SELECT status, last_error FROM jobs')->fetch();
        $this->assertSame('pending', $row['status'], 'The site clock must keep ticking even after repeated failures');
        $this->assertSame('boom', $row['last_error']);
    }

    public function testEnsureRecurringCreatesExactlyOneRow(): void
    {
        $this->queue->ensureRecurring('test.tick');
        $this->queue->ensureRecurring('test.tick');

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    public function testRunnerDispatchesToHandlerAndRecordsHeartbeat(): void
    {
        $seen   = [];
        $runner = new JobRunner($this->queue, 'worker-test');
        $runner->register('test.collect', new class ($seen) implements JobHandler {
            /** @param array<int, string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function handle(Job $job): void
            {
                $this->seen[] = $job->id;
            }
        });

        $id = $this->queue->push('test.collect');
        $result = $runner->run(5);

        $this->assertSame([$id], $seen);
        $this->assertSame(1, $result['ran']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(0, $result['pending']);
        $this->assertNotNull($this->queue->heartbeatAt());
    }

    public function testRunnerRecordsFailureWhenNoHandlerIsRegistered(): void
    {
        $this->queue->push('plugin.gone');

        $result = (new JobRunner($this->queue, 'worker-test'))->run(5);

        $this->assertSame(0, $result['ran']);
        $this->assertSame(1, $result['failed']);
        $this->assertStringContainsString(
            'plugin.gone',
            (string) $this->pdo->query('SELECT last_error FROM jobs')->fetchColumn()
        );
    }

    public function testRunnerPublishesDueScheduledPostsViaCoreHandler(): void
    {
        $due    = $this->insertScheduledPost('due-post', '-1 hour');
        $future = $this->insertScheduledPost('future-post', '+1 hour');

        $result = JobRunner::withCoreHandlers($this->pdo)->run(5);
        $this->assertSame(1, $result['ran'], 'The recurring publisher should have been created and run');
        $this->assertSame(0, $result['pending'], 'The re-armed publisher is not due again yet');

        $this->assertSame('published', $this->postStatus($due));
        $this->assertSame('scheduled', $this->postStatus($future));

        $publishedAt = $this->pdo->query("SELECT published_at FROM posts WHERE id = '{$due}'")->fetchColumn();
        $this->assertNotNull($publishedAt, 'published_at is backfilled from scheduled_at');
    }

    public function testPublishDueIsIdempotent(): void
    {
        $this->insertScheduledPost('due-post', '-1 hour');

        $publisher = new PublishScheduledPostsJob($this->pdo);
        $this->assertSame(1, $publisher->publishDue());
        $this->assertSame(0, $publisher->publishDue(), 'A second run must not touch already-published posts');
    }

    private function insertScheduledPost(string $slug, string $when): string
    {
        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO posts (id, slug, title, status, locale, scheduled_at, created_at, updated_at)
             VALUES (?, ?, ?, 'scheduled', 'en', ?, ?, ?)"
        )->execute([
            $id,
            $slug,
            ucfirst($slug),
            (new \DateTimeImmutable($when))->format('Y-m-d H:i:s'),
            $now,
            $now,
        ]);

        return $id;
    }

    private function postStatus(string $id): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM posts WHERE id = ?');
        $stmt->execute([$id]);

        return (string) $stmt->fetchColumn();
    }
}
