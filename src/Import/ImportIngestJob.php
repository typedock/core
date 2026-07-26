<?php
declare(strict_types=1);

namespace TypeDock\Import;

use TypeDock\Core\Queue\Job;
use TypeDock\Core\Queue\JobHandler;
use TypeDock\Core\Queue\JobRunner;

/**
 * Carries an import forward one slice at a time.
 *
 * This is what makes "close the tab and it keeps going" true: the progress
 * page and a cron worker drive exactly the same job, so an import continues
 * whether or not anyone is watching.
 *
 * Unlike media, ingestion is *one* job rather than one per document. Writing a
 * row to the local database is not a fallible network operation, so per-item
 * retry buys nothing — while a job per post would park tens of megabytes of
 * converted content in `jobs.payload` on a shared host's database.
 */
final class ImportIngestJob implements JobHandler
{
    public function __construct(private readonly ImportService $imports)
    {
    }

    public function handle(Job $job): void
    {
        $importId = (string) ($job->payload['import_id'] ?? '');
        if ($importId === '') {
            return;
        }

        // Half the worker's budget, so a tick that starts this job close to
        // its deadline still returns in time to render a response. The runner
        // checks the clock before claiming, not during a job.
        $slice  = max(3, (int) (JobRunner::defaultBudget() / 2));
        $result = $this->imports->advance($importId, microtime(true) + $slice);

        if (!$result['done']) {
            // Re-arm immediately: there is more file to read, and leaving it
            // for the next tick would stall a large import behind whatever
            // else is queued.
            $this->imports->enqueue($importId);
        }
    }
}
