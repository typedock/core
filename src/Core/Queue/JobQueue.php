<?php
declare(strict_types=1);

namespace TypeDock\Core\Queue;

/**
 * Persistence layer for the background job queue.
 *
 * Claiming is deliberately portable: a candidate SELECT with no locking,
 * followed by a conditional UPDATE per candidate. Whoever's UPDATE reports one
 * affected row owns the job; everyone else moves on. That costs an occasional
 * wasted round-trip under contention but works identically on MySQL,
 * PostgreSQL and SQLite, which `FOR UPDATE SKIP LOCKED` does not.
 */
final class JobQueue
{
    /** Attempts after which a one-shot job is parked as `failed`. */
    public const MAX_ATTEMPTS = 5;

    private const HEARTBEAT_KEY = 'queue.heartbeat';

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Enqueue a job. Returns the job id.
     *
     * @param array<string, mixed> $payload
     */
    public function push(
        string $handler,
        array $payload = [],
        ?string $batchId = null,
        int $delaySeconds = 0,
        string $queue = 'default',
    ): string {
        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = $this->now();

        $this->pdo->prepare(
            'INSERT INTO jobs (id, queue, handler, payload, status, batch_id, attempts, run_after, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            $id,
            $queue,
            $handler,
            $payload === [] ? null : json_encode($payload),
            'pending',
            $batchId,
            $delaySeconds > 0 ? $this->at($delaySeconds) : null,
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * Create the single row that drives a recurring handler, unless it is
     * already there. Recurring jobs are never deleted or parked — they are
     * re-armed on every outcome — so "no row for this handler" only happens on
     * a fresh install or after an operator clears the table.
     */
    public function ensureRecurring(string $handler, string $queue = 'default'): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM jobs WHERE handler = ? AND queue = ? LIMIT 1');
        $stmt->execute([$handler, $queue]);
        if ($stmt->fetchColumn() !== false) {
            return;
        }

        $this->push($handler, [], null, 0, $queue);
    }

    /**
     * Take ownership of one runnable job, or return null when there is nothing
     * to do. `$leaseSeconds` should comfortably exceed the worst-case runtime
     * of a single job: while the lease holds, no other worker will touch it.
     */
    public function claim(string $workerId, string $queue = 'default', int $leaseSeconds = 300): ?Job
    {
        $now = $this->now();

        $candidates = $this->pdo->prepare(
            "SELECT id, queue, handler, payload, attempts, batch_id
               FROM jobs
              WHERE queue = ?
                AND ( (status = 'pending' AND (run_after IS NULL OR run_after <= ?))
                   OR (status = 'running' AND lease_until IS NOT NULL AND lease_until < ?) )
              ORDER BY created_at
              LIMIT 20"
        );
        $candidates->execute([$queue, $now, $now]);
        $rows = $candidates->fetchAll();

        $take = $this->pdo->prepare(
            "UPDATE jobs
                SET status = 'running', worker_id = ?, lease_until = ?, attempts = attempts + 1, updated_at = ?
              WHERE id = ?
                AND ( status = 'pending'
                   OR (status = 'running' AND lease_until IS NOT NULL AND lease_until < ?) )"
        );

        foreach ($rows as $row) {
            $take->execute([$workerId, $this->at($leaseSeconds), $now, $row['id'], $now]);
            if ($take->rowCount() === 1) {
                // attempts was incremented by the claim, so reflect that in the
                // object the handler sees.
                $row['attempts'] = (int) $row['attempts'] + 1;
                return Job::fromRow($row);
            }
        }

        return null;
    }

    /**
     * Mark a job finished. One-shot jobs are removed; a recurring job
     * ($repeatAfterSeconds > 0) goes back to `pending`, due one interval from
     * now, with its attempt counter and last error cleared.
     */
    public function complete(Job $job, int $repeatAfterSeconds = 0): void
    {
        if ($repeatAfterSeconds > 0) {
            $this->pdo->prepare(
                "UPDATE jobs
                    SET status = 'pending', attempts = 0, run_after = ?, lease_until = NULL,
                        worker_id = NULL, last_error = NULL, updated_at = ?
                  WHERE id = ?"
            )->execute([$this->at($repeatAfterSeconds), $this->now(), $job->id]);
            return;
        }

        $this->pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$job->id]);
    }

    /**
     * Record a failed attempt. A recurring job always gets another go — it is
     * the site's clock, and parking it would silently stop scheduled work — so
     * it is re-armed at whichever is later, its own interval or the backoff.
     * A one-shot job retries with backoff until MAX_ATTEMPTS, then parks as
     * `failed` for an operator to see.
     */
    public function fail(Job $job, string $error, int $repeatAfterSeconds = 0): void
    {
        $error   = mb_substr($error, 0, 2000);
        $backoff = $this->backoffSeconds($job->attempts);

        if ($repeatAfterSeconds > 0) {
            $this->pdo->prepare(
                "UPDATE jobs
                    SET status = 'pending', run_after = ?, lease_until = NULL, worker_id = NULL,
                        last_error = ?, updated_at = ?
                  WHERE id = ?"
            )->execute([$this->at(max($repeatAfterSeconds, $backoff)), $error, $this->now(), $job->id]);
            return;
        }

        if ($job->attempts >= self::MAX_ATTEMPTS) {
            $this->pdo->prepare(
                "UPDATE jobs
                    SET status = 'failed', lease_until = NULL, worker_id = NULL,
                        last_error = ?, updated_at = ?
                  WHERE id = ?"
            )->execute([$error, $this->now(), $job->id]);
            return;
        }

        $this->pdo->prepare(
            "UPDATE jobs
                SET status = 'pending', run_after = ?, lease_until = NULL, worker_id = NULL,
                    last_error = ?, updated_at = ?
              WHERE id = ?"
        )->execute([$this->at($backoff), $error, $this->now(), $job->id]);
    }

    /**
     * How much work is runnable right now — same predicate as claim().
     *
     * Deliberately not "every row with status = pending": a recurring job
     * waiting out its interval, or a failed job waiting out its backoff, is
     * not work anyone can pick up, and counting it would make a caller that
     * ticks "while pending > 0" spin forever.
     */
    public function dueCount(string $queue = 'default'): int
    {
        $now  = $this->now();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM jobs
              WHERE queue = ?
                AND ( (status = 'pending' AND (run_after IS NULL OR run_after <= ?))
                   OR (status = 'running' AND lease_until IS NOT NULL AND lease_until < ?) )"
        );
        $stmt->execute([$queue, $now, $now]);

        return (int) $stmt->fetchColumn();
    }

    /** Jobs that ran out of attempts and need a human. */
    public function failedCount(string $queue = 'default'): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM jobs WHERE queue = ? AND status = 'failed'");
        $stmt->execute([$queue]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The most recent permanently-failed job, for the admin warning banner.
     *
     * @return array{handler:string, last_error:string, updated_at:string}|null
     */
    public function lastFailure(string $queue = 'default'): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT handler, last_error, updated_at FROM jobs
              WHERE queue = ? AND status = 'failed'
              ORDER BY updated_at DESC LIMIT 1"
        );
        $stmt->execute([$queue]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'handler'    => (string) $row['handler'],
            'last_error' => (string) ($row['last_error'] ?? ''),
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * Stamp "a worker ran just now". Read back by the dashboard to tell an
     * operator that background work has stopped — the one failure mode a
     * queue cannot report on its own.
     */
    public function recordHeartbeat(): void
    {
        $now   = $this->now();
        $value = json_encode($now);

        $stmt = $this->pdo->prepare('SELECT 1 FROM site_options WHERE key_name = ? LIMIT 1');
        $stmt->execute([self::HEARTBEAT_KEY]);

        if ($stmt->fetchColumn() !== false) {
            $this->pdo->prepare('UPDATE site_options SET value = ?, updated_at = ? WHERE key_name = ?')
                ->execute([$value, $now, self::HEARTBEAT_KEY]);
            return;
        }

        $this->pdo->prepare('INSERT INTO site_options (key_name, value, group_name, updated_at) VALUES (?, ?, ?, ?)')
            ->execute([self::HEARTBEAT_KEY, $value, 'system', $now]);
    }

    public function heartbeatAt(): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM site_options WHERE key_name = ? LIMIT 1');
        $stmt->execute([self::HEARTBEAT_KEY]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    /** 10s, 20s, 40s … capped at an hour. */
    private function backoffSeconds(int $attempts): int
    {
        return (int) min(3600, 10 * (2 ** max(0, $attempts - 1)));
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function at(int $offsetSeconds): string
    {
        return (new \DateTimeImmutable())->modify("+{$offsetSeconds} seconds")->format('Y-m-d H:i:s');
    }
}
