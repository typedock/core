<?php
declare(strict_types=1);

namespace TypeDock\Module\Redirect;

use TypeDock\Contract\ModuleInterface;
use TypeDock\Middleware\RedirectMiddleware;

class RedirectModule implements ModuleInterface
{
    public function register(): void
    {
        // Register a regex-pattern resolver that augments the core exact-match table.
        // Admin CRUD (exact-match) already lives in TypeDock\Admin\RedirectController.
        RedirectMiddleware::addResolver(new RegexRedirectResolver(\Flight::db()));
    }
}
