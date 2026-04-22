<?php
declare(strict_types=1);

namespace TypeDock\Module\Antispam;

use TypeDock\Contract\ModuleInterface;

class AntispamModule implements ModuleInterface
{
    public function register(): void
    {
        \Flight::map('antispam', function (): AntispamService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new AntispamService(
                \Flight::db(),
                (string) config('antispam.honeypot_field', 'website'),
                (int) config('antispam.rate_limit', 5),
                (int) config('antispam.window_seconds', 60)
            );
            return $service;
        });
    }
}
