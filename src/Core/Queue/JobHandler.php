<?php
declare(strict_types=1);

namespace TypeDock\Core\Queue;

interface JobHandler
{
    /**
     * Perform the work. The queue guarantees at-least-once delivery — a worker
     * that dies mid-job hands the same job to the next worker once its lease
     * expires — so handlers must be safe to run twice.
     *
     * Throwing schedules a retry with exponential backoff; returning normally
     * completes the job.
     */
    public function handle(Job $job): void;
}
