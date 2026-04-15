<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Admin\AuthController;
use TypeDock\Admin\DashboardController;
use TypeDock\Admin\PostController;
use TypeDock\Admin\PageController;
use TypeDock\Admin\MediaController;
use TypeDock\Admin\MenuController;
use TypeDock\Admin\CategoryController;
use TypeDock\Admin\TagController;
use TypeDock\Admin\UserController;
use TypeDock\Admin\SettingsController;
use TypeDock\Admin\RedirectController;
use TypeDock\Admin\SlotController;
use TypeDock\Frontend\FrontendController;
use TypeDock\Api\ApiController;
use TypeDock\Middleware\AuthMiddleware;
use TypeDock\Middleware\CsrfMiddleware;

class Router
{
    public function register(): void
    {
        $this->registerSystemRoutes();
        $this->registerAdminRoutes();
        $this->registerApiRoutes();
        $this->registerBlogRoutes();
        $this->registerFrontendRoutes();
    }

    private function registerSystemRoutes(): void
    {
        // Sitemap
        \Flight::route('GET /sitemap.xml', function () {
            (new \TypeDock\Seo\SitemapController())->index();
        });

        // RSS feed
        \Flight::route('GET /feed', function () {
            (new \TypeDock\Seo\RssController())->index();
        });

        // Robots.txt
        \Flight::route('GET /robots.txt', function () {
            header('Content-Type: text/plain');
            $rules = "User-agent: *\nAllow: /\nSitemap: " . config('app.url') . "/sitemap.xml\n";
            echo $rules;
        });
    }

    private function registerAdminRoutes(): void
    {
        $auth = new AuthMiddleware();
        $csrf = new CsrfMiddleware();

        // Login (no auth required)
        \Flight::route('GET /admin/login', [new AuthController(), 'showLogin']);
        \Flight::route('POST /admin/login', function () use ($csrf) {
            $csrf->verifyOrFail();
            (new AuthController())->processLogin();
        });
        \Flight::route('GET /admin/login/2fa', [new AuthController(), 'showTwoFactor']);
        \Flight::route('POST /admin/login/2fa', function () use ($csrf) {
            $csrf->verifyOrFail();
            (new AuthController())->processTwoFactor();
        });
        \Flight::route('GET /admin/logout', function () use ($auth) {
            $auth->requireAuth();
            (new AuthController())->logout();
        });

        // Dashboard
        \Flight::route('GET /admin', function () use ($auth) {
            $auth->requireAuth();
            (new DashboardController())->index();
        });
        \Flight::route('GET /admin/dashboard', function () use ($auth) {
            $auth->requireAuth();
            (new DashboardController())->index();
        });

        // Posts
        \Flight::route('GET /admin/posts', function () use ($auth) {
            $auth->requireAuth();
            (new PostController())->index();
        });
        \Flight::route('GET /admin/posts/create', function () use ($auth) {
            $auth->requireAuth();
            (new PostController())->create();
        });
        \Flight::route('POST /admin/posts/create', function () use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new PostController())->store();
        });
        \Flight::route('GET /admin/posts/@id/edit', function (string $id) use ($auth) {
            $auth->requireAuth();
            (new PostController())->edit($id);
        });
        \Flight::route('POST /admin/posts/@id/edit', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new PostController())->update($id);
        });
        \Flight::route('POST /admin/posts/@id/delete', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new PostController())->destroy($id);
        });

        // Pages
        \Flight::route('GET /admin/pages', function () use ($auth) {
            $auth->requireAuth();
            (new PageController())->index();
        });
        \Flight::route('GET /admin/pages/create', function () use ($auth) {
            $auth->requireAuth();
            (new PageController())->create();
        });
        \Flight::route('POST /admin/pages/create', function () use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new PageController())->store();
        });
        \Flight::route('GET /admin/pages/@id/edit', function (string $id) use ($auth) {
            $auth->requireAuth();
            (new PageController())->edit($id);
        });
        \Flight::route('POST /admin/pages/@id/edit', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new PageController())->update($id);
        });
        \Flight::route('POST /admin/pages/@id/delete', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new PageController())->destroy($id);
        });

        // Media
        \Flight::route('GET /admin/media', function () use ($auth) {
            $auth->requireAuth();
            (new MediaController())->index();
        });
        \Flight::route('POST /admin/media/delete/@id', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new MediaController())->destroy($id);
        });

        // Menus
        \Flight::route('GET /admin/menus', function () use ($auth) {
            $auth->requireAuth();
            (new MenuController())->index();
        });
        \Flight::route('POST /admin/menus', function () use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new MenuController())->store();
        });
        \Flight::route('GET /admin/menus/@id', function (string $id) use ($auth) {
            $auth->requireAuth();
            (new MenuController())->edit($id);
        });
        \Flight::route('POST /admin/menus/@id', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new MenuController())->update($id);
        });
        \Flight::route('POST /admin/menus/@id/delete', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new MenuController())->destroy($id);
        });

        // Categories
        \Flight::route('GET /admin/categories', function () use ($auth) {
            $auth->requireAuth();
            (new CategoryController())->index();
        });
        \Flight::route('POST /admin/categories', function () use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new CategoryController())->store();
        });
        \Flight::route('POST /admin/categories/@id/delete', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new CategoryController())->destroy($id);
        });

        // Tags
        \Flight::route('GET /admin/tags', function () use ($auth) {
            $auth->requireAuth();
            (new TagController())->index();
        });
        \Flight::route('POST /admin/tags', function () use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new TagController())->store();
        });
        \Flight::route('POST /admin/tags/@id/delete', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new TagController())->destroy($id);
        });

        // Users
        \Flight::route('GET /admin/users', function () use ($auth) {
            $auth->requireAuth('admin');
            (new UserController())->index();
        });
        \Flight::route('GET /admin/users/create', function () use ($auth) {
            $auth->requireAuth('admin');
            (new UserController())->create();
        });
        \Flight::route('POST /admin/users/create', function () use ($auth, $csrf) {
            $auth->requireAuth('admin');
            $csrf->verifyOrFail();
            (new UserController())->store();
        });
        \Flight::route('GET /admin/users/@id/edit', function (string $id) use ($auth) {
            $auth->requireAuth('admin');
            (new UserController())->edit($id);
        });
        \Flight::route('POST /admin/users/@id/edit', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth('admin');
            $csrf->verifyOrFail();
            (new UserController())->update($id);
        });
        \Flight::route('POST /admin/users/@id/delete', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth('admin');
            $csrf->verifyOrFail();
            (new UserController())->destroy($id);
        });

        // Settings
        \Flight::route('GET /admin/settings', function () use ($auth) {
            $auth->requireAuth('admin');
            (new SettingsController())->general();
        });
        \Flight::route('GET /admin/settings/general', function () use ($auth) {
            $auth->requireAuth('admin');
            (new SettingsController())->general();
        });
        \Flight::route('POST /admin/settings/general', function () use ($auth, $csrf) {
            $auth->requireAuth('admin');
            $csrf->verifyOrFail();
            (new SettingsController())->updateGeneral();
        });
        \Flight::route('GET /admin/settings/seo', function () use ($auth) {
            $auth->requireAuth('admin');
            (new SettingsController())->seo();
        });
        \Flight::route('POST /admin/settings/seo', function () use ($auth, $csrf) {
            $auth->requireAuth('admin');
            $csrf->verifyOrFail();
            (new SettingsController())->updateSeo();
        });
        \Flight::route('GET /admin/settings/modules', function () use ($auth) {
            $auth->requireAuth('admin');
            (new SettingsController())->modules();
        });

        // Redirects
        \Flight::route('GET /admin/redirects', function () use ($auth) {
            $auth->requireAuth();
            (new RedirectController())->index();
        });
        \Flight::route('POST /admin/redirects', function () use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new RedirectController())->store();
        });
        \Flight::route('POST /admin/redirects/@id/delete', function (string $id) use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new RedirectController())->destroy($id);
        });

        // Slots
        \Flight::route('GET /admin/slots', function () use ($auth) {
            $auth->requireAuth();
            (new SlotController())->index();
        });
        \Flight::route('POST /admin/slots', function () use ($auth, $csrf) {
            $auth->requireAuth();
            $csrf->verifyOrFail();
            (new SlotController())->update();
        });

        // Admin internal API (for JS islands)
        \Flight::route('POST /admin/api/media/upload', function () use ($auth) {
            $auth->requireAuthJson();
            (new MediaController())->upload();
        });
        \Flight::route('POST /admin/api/pages/@id/autosave', function (string $id) use ($auth) {
            $auth->requireAuthJson();
            (new PostController())->autosave($id);
        });
        \Flight::route('GET /admin/api/oembed', function () use ($auth) {
            $auth->requireAuthJson();
            (new \TypeDock\Admin\OembedController())->resolve();
        });
        \Flight::route('GET /admin/api/link-card', function () use ($auth) {
            $auth->requireAuthJson();
            (new \TypeDock\Admin\LinkCardController())->resolve();
        });
    }

    private function registerApiRoutes(): void
    {
        // External REST API (APIKey auth)
        \Flight::route('GET /api/v1/pages', function () {
            (new ApiController())->listPages();
        });
        \Flight::route('GET /api/v1/pages/@id', function (string $id) {
            (new ApiController())->getPage($id);
        });
        \Flight::route('GET /api/v1/media', function () {
            (new ApiController())->listMedia();
        });
        \Flight::route('POST /api/v1/media', function () {
            (new ApiController())->uploadMedia();
        });
    }

    private function registerBlogRoutes(): void
    {
        // Blog archive
        \Flight::route('GET /blog', function () {
            (new FrontendController())->blogIndex();
        });
        \Flight::route('GET /blog/page/@page', function (string $page) {
            (new FrontendController())->blogIndex((int) $page);
        });

        // Blog single post
        \Flight::route('GET /blog/@slug', function (string $slug) {
            (new FrontendController())->blogPost($slug);
        });

        // Category archive
        \Flight::route('GET /category/@slug', function (string $slug) {
            (new FrontendController())->categoryArchive($slug);
        });
        \Flight::route('GET /category/@slug/page/@page', function (string $slug, string $page) {
            (new FrontendController())->categoryArchive($slug, (int) $page);
        });

        // Tag archive
        \Flight::route('GET /tag/@slug', function (string $slug) {
            (new FrontendController())->tagArchive($slug);
        });
        \Flight::route('GET /tag/@slug/page/@page', function (string $slug, string $page) {
            (new FrontendController())->tagArchive($slug, (int) $page);
        });

        // Search
        \Flight::route('GET /search', function () {
            (new FrontendController())->search();
        });
    }

    private function registerFrontendRoutes(): void
    {
        // Homepage
        \Flight::route('GET /', function () {
            (new FrontendController())->homepage();
        });

        // Catchall for static pages (must be last)
        \Flight::route('GET /*', function () {
            (new FrontendController())->page();
        });
    }
}
