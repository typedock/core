<?php
declare(strict_types=1);

namespace TypeDock\Admin;

class QueueController extends BaseAdminController
{
    /**
     * Browser-driven worker tick — the zero-configuration default.
     *
     * On hosting without cron this is the only thing that moves the queue, so
     * it is open to any authenticated admin user rather than gated behind a
     * permission: the work it performs is the site's own scheduled work, and
     * refusing it for a contributor would just mean scheduled posts miss their
     * slot whenever a contributor is the one browsing.
     */
    public function tick(): void
    {
        $result = \Flight::job_runner()->run();

        \Flight::json($result);
    }
}
