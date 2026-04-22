<?php
declare(strict_types=1);

namespace TypeDock\Module\Collection;

use TypeDock\Contract\ModuleInterface;
use TypeDock\Middleware\AuthMiddleware;
use TypeDock\Middleware\CsrfMiddleware;

class CollectionModule implements ModuleInterface
{
    public function register(): void
    {
        // Service binding
        \Flight::map('collections', function (): CollectionService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new CollectionService(\Flight::db());
            return $service;
        });

        $this->registerRoutes();
    }

    private function registerRoutes(): void
    {
        $auth = new AuthMiddleware();
        $csrf = new CsrfMiddleware();

        \Flight::route('GET /admin/collections', function () use ($auth) {
            $auth->requireAuth();
            (new CollectionAdminController())->index();
        });
        \Flight::route('POST /admin/collections', function () use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new CollectionAdminController())->store();
        });
        \Flight::route('POST /admin/collections/@id/delete', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new CollectionAdminController())->destroy($id);
        });
        \Flight::route('GET /admin/collections/@id/items', function (string $id) use ($auth) {
            $auth->requireAuth();
            (new CollectionAdminController())->items($id);
        });
        \Flight::route('POST /admin/collections/@id/items', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new CollectionAdminController())->storeItem($id);
        });
        \Flight::route('POST /admin/collections/@id/items/@itemId/delete', function (string $id, string $itemId) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new CollectionAdminController())->destroyItem($id, $itemId);
        });
    }
}
