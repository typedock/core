<?php
declare(strict_types=1);

namespace TypeDock\Core\Queue;

use TypeDock\Content\PublishScheduledPostsJob;
use TypeDock\Import\ImportMediaJob;
use TypeDock\Media\MediaService;

/**
 * Drains the queue for a bounded slice of time.
 *
 * The unit of execution is a *tick*: claim and run jobs until the time budget
 * runs out, then return. Nothing here assumes a long-lived process, because
 * the primary deployment target is a $5 shared host where `set_time_limit(0)`
 * is disabled and the only reliable trigger is an HTTP request. A cron entry
 * or a supervised daemon simply calls the same tick with a bigger budget.
 */
final class JobRunner
{
    /** How long a claimed job is ours before another worker may steal it. */
    private const LEASE_SECONDS = 300;

    /** Budget used when PHP reports no execution limit (CLI, or unlimited). */
    private const DEFAULT_BUDGET_SECONDS = 20;

    /** Stop the loop once we are this close to the memory ceiling. */
    private const MEMORY_HEADROOM = 0.8;

    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /** @var array<string, int> Handler name => repeat interval in seconds. */
    private array $intervals = [];

    public function __construct(
        private readonly JobQueue $queue,
        private readonly string $workerId = '',
    ) {
    }

    /**
     * The handler set Core ships with. Shared by the HTTP tick and
     * `cli/queue-work.php` so the two can never drift apart.
     */
    public static function withCoreHandlers(\PDO $pdo, MediaService $media): self
    {
        $runner = new self(new JobQueue($pdo));
        $runner->register('posts.publish_scheduled', new PublishScheduledPostsJob($pdo), 60);
        $runner->register('import.media', new ImportMediaJob($pdo, $media));

        return $runner;
    }

    /**
     * @param int $repeatEverySeconds >0 makes this a recurring job: the queue
     *                                keeps exactly one row for it and re-arms
     *                                it after every run.
     */
    public function register(string $name, JobHandler $handler, int $repeatEverySeconds = 0): void
    {
        $this->handlers[$name] = $handler;
        if ($repeatEverySeconds > 0) {
            $this->intervals[$name] = $repeatEverySeconds;
        }
    }

    /**
     * Run one tick.
     *
     * @return array{ran:int, failed:int, pending:int}
     */
    public function run(?int $budgetSeconds = null, string $queue = 'default'): array
    {
        // Finish the job in flight even if the browser tab that triggered this
        // tick goes away; the alternative is a job killed mid-write that has to
        // wait out its whole lease before anyone retries it.
        ignore_user_abort(true);

        $budget = $budgetSeconds ?? self::defaultBudget();
        @set_time_limit($budget + 30);   // best effort — many shared hosts refuse

        $deadline = microtime(true) + $budget;
        $workerId = $this->workerId !== '' ? $this->workerId : self::defaultWorkerId();

        foreach (array_keys($this->intervals) as $handler) {
            $this->queue->ensureRecurring($handler, $queue);
        }

        $ran    = 0;
        $failed = 0;

        while (microtime(true) < $deadline) {
            if (self::memoryExhausted()) {
                // Better to stop one job short and pick up on the next tick
                // than to be OOM-killed halfway through one.
                break;
            }

            $job = $this->queue->claim($workerId, $queue, self::LEASE_SECONDS);
            if ($job === null) {
                break;
            }

            $repeat = $this->intervals[$job->handler] ?? 0;

            try {
                $handler = $this->handlers[$job->handler]
                    ?? throw new \RuntimeException("No handler registered for '{$job->handler}'");
                $handler->handle($job);
                $this->queue->complete($job, $repeat);
                $ran++;
            } catch (\Throwable $e) {
                $this->queue->fail($job, $e->getMessage(), $repeat);
                $failed++;
            }
        }

        $this->queue->recordHeartbeat();

        return [
            'ran'     => $ran,
            'failed'  => $failed,
            'pending' => $this->queue->dueCount($queue),
        ];
    }

    /**
     * Leave enough of the request's execution time for the response itself.
     * Deliberately does not try `set_time_limit(0)`: shared hosts disable it,
     * and a worker that only works when it is unlimited works nowhere.
     */
    public static function defaultBudget(): int
    {
        $max = (int) ini_get('max_execution_time');

        return $max > 0 ? max(5, (int) ($max * 0.6)) : self::DEFAULT_BUDGET_SECONDS;
    }

    private static function defaultWorkerId(): string
    {
        return substr((string) gethostname() . ':' . getmypid(), 0, 64);
    }

    private static function memoryExhausted(): bool
    {
        $limit = self::memoryLimitBytes();

        return $limit > 0 && memory_get_usage(true) > $limit * self::MEMORY_HEADROOM;
    }

    /** 0 when unlimited or unparseable. */
    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }

        $value = (int) $raw;
        return match (strtolower($raw[strlen($raw) - 1])) {
            'g'     => $value * 1024 ** 3,
            'm'     => $value * 1024 ** 2,
            'k'     => $value * 1024,
            default => $value,
        };
    }
}
