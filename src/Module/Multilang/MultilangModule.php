<?php
declare(strict_types=1);

namespace TypeDock\Module\Multilang;

use TypeDock\Contract\ModuleInterface;

class MultilangModule implements ModuleInterface
{
    public function register(): void
    {
        \Flight::map('locales', function (): LocaleService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new LocaleService(\Flight::db());
            return $service;
        });

        // Register locale resolution before route dispatch
        \Flight::before('start', function (): void {
            (new LocaleMiddleware(\Flight::locales()))->handle();
        });
    }
}
