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
use TypeDock\Admin\SlotController;
use TypeDock\Admin\ThemeController;
use TypeDock\Admin\ThemeSettingsController;
use TypeDock\Admin\ExternalSourceController;
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
        // Public admin routes (login flow). CSRF is applied as group middleware
        // so POSTs are verified without repeating the call in each handler;
        // safe verbs (GET/HEAD) pass through CsrfMiddleware::before() untouched.
        \Flight::group('/admin', function () {
            \Flight::route('GET /login', [new AuthController(), 'showLogin']);
            \Flight::route('POST /login', function () {
                (new AuthController())->processLogin();
            });
            \Flight::route('GET /login/2fa', [new AuthController(), 'showTwoFactor']);
            \Flight::route('POST /login/2fa', function () {
                (new AuthController())->processTwoFactor();
            });
        }, [CsrfMiddleware::class]);

        // Authenticated HTML admin. AuthMiddleware::before() redirects
        // unauthenticated requests to /admin/login, so route handlers assume a
        // live session. Role- and permission-specific checks remain inline.
        \Flight::group('/admin', function () {
            $auth = new AuthMiddleware();

            \Flight::route('GET /logout', function () {
                (new AuthController())->logout();
            });

            // Dashboard
            \Flight::route('GET /', function () {
                (new DashboardController())->index();
            });
            \Flight::route('GET /dashboard', function () {
                (new DashboardController())->index();
            });

            // Posts
            \Flight::route('GET /posts', function () {
                (new PostController())->index();
            });
            \Flight::route('GET /posts/create', function () {
                (new PostController())->create();
            });
            \Flight::route('POST /posts/create', function () {
                (new PostController())->store();
            });
            \Flight::route('GET /posts/@id/edit', function (string $id) {
                (new PostController())->edit($id);
            });
            \Flight::route('POST /posts/@id/edit', function (string $id) {
                (new PostController())->update($id);
            });
            \Flight::route('POST /posts/@id/delete', function (string $id) {
                (new PostController())->destroy($id);
            });

            // Pages
            \Flight::route('GET /pages', function () {
                (new PageController())->index();
            });
            \Flight::route('GET /pages/create', function () {
                (new PageController())->create();
            });
            \Flight::route('POST /pages/create', function () {
                (new PageController())->store();
            });
            \Flight::route('GET /pages/@id/edit', function (string $id) {
                (new PageController())->edit($id);
            });
            \Flight::route('POST /pages/@id/edit', function (string $id) {
                (new PageController())->update($id);
            });
            \Flight::route('POST /pages/@id/delete', function (string $id) {
                (new PageController())->destroy($id);
            });

            // Media
            \Flight::route('GET /media', function () {
                (new MediaController())->index();
            });
            \Flight::route('GET /media/@id', function (string $id) {
                (new MediaController())->edit($id);
            });
            \Flight::route('POST /media/@id', function (string $id) {
                (new MediaController())->update($id);
            });
            \Flight::route('POST /media/delete/@id', function (string $id) {
                (new MediaController())->destroy($id);
            });

            // Menus (location-driven — themes declare locations in theme.json)
            \Flight::route('GET /menus', function () use ($auth) {
                $auth->requirePermission('menus:manage');
                (new MenuController())->index();
            });
            \Flight::route('GET /menus/@location', function (string $location) use ($auth) {
                $auth->requirePermission('menus:manage');
                (new MenuController())->edit($location);
            });
            \Flight::route('POST /menus/@location/items', function (string $location) use ($auth) {
                $auth->requirePermission('menus:manage');
                (new MenuController())->storeItem($location);
            });
            \Flight::route('POST /menus/@location/items/@itemId/delete', function (string $location, string $itemId) use ($auth) {
                $auth->requirePermission('menus:manage');
                (new MenuController())->destroyItem($location, $itemId);
            });

            // Categories
            \Flight::route('GET /categories', function () use ($auth) {
                $auth->requirePermission('categories:manage');
                (new CategoryController())->index();
            });
            \Flight::route('POST /categories', function () use ($auth) {
                $auth->requirePermission('categories:manage');
                (new CategoryController())->store();
            });
            \Flight::route('POST /categories/@id/delete', function (string $id) use ($auth) {
                $auth->requirePermission('categories:manage');
                (new CategoryController())->destroy($id);
            });

            // Tags
            \Flight::route('GET /tags', function () use ($auth) {
                $auth->requirePermission('tags:manage');
                (new TagController())->index();
            });
            \Flight::route('POST /tags', function () use ($auth) {
                $auth->requirePermission('tags:manage');
                (new TagController())->store();
            });
            \Flight::route('POST /tags/@id/delete', function (string $id) use ($auth) {
                $auth->requirePermission('tags:manage');
                (new TagController())->destroy($id);
            });

            // Users
            \Flight::route('GET /users', function () use ($auth) {
                $auth->requireAuth('admin');
                (new UserController())->index();
            });
            \Flight::route('GET /users/create', function () use ($auth) {
                $auth->requireAuth('admin');
                (new UserController())->create();
            });
            \Flight::route('POST /users/create', function () use ($auth) {
                $auth->requireAuth('admin');
                (new UserController())->store();
            });
            \Flight::route('GET /users/@id/edit', function (string $id) use ($auth) {
                $auth->requireAuth('admin');
                (new UserController())->edit($id);
            });
            \Flight::route('POST /users/@id/edit', function (string $id) use ($auth) {
                $auth->requireAuth('admin');
                (new UserController())->update($id);
            });
            \Flight::route('POST /users/@id/delete', function (string $id) use ($auth) {
                $auth->requireAuth('admin');
                (new UserController())->destroy($id);
            });

            // Settings
            \Flight::route('GET /settings', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->general();
            });
            \Flight::route('GET /settings/general', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->general();
            });
            \Flight::route('POST /settings/general', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->updateGeneral();
            });
            \Flight::route('GET /settings/seo', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->seo();
            });
            \Flight::route('POST /settings/seo', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->updateSeo();
            });
            \Flight::route('GET /settings/mail', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->mail();
            });
            \Flight::route('POST /settings/mail', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->updateMail();
            });
            \Flight::route('POST /settings/mail/test', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->testMail();
            });
            \Flight::route('GET /settings/modules', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->modules();
            });
            \Flight::route('POST /settings/modules/plugins/toggle', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->togglePlugin();
            });
            \Flight::route('POST /settings/modules/plugins/upload', function () use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->uploadPlugin();
            });
            \Flight::route('GET /plugins/@slug/docs', function (string $slug) use ($auth) {
                $auth->requireAuth('admin');
                (new SettingsController())->pluginDocs($slug);
            });

            // Redirects are contributed by the drop-in Redirect plugin. Core only
            // owns the middleware runner and RedirectResolver contract.

            // Slots
            \Flight::route('GET /slots', function () use ($auth) {
                $auth->requirePermission('slots:manage');
                (new SlotController())->index();
            });
            \Flight::route('POST /slots', function () use ($auth) {
                $auth->requirePermission('slots:manage');
                (new SlotController())->update();
            });

            // External Sources
            \Flight::route('GET /external-sources', function () use ($auth) {
                $auth->requirePermission('external_sources:manage');
                (new ExternalSourceController())->index();
            });
            \Flight::route('GET /external-sources/create', function () use ($auth) {
                $auth->requirePermission('external_sources:manage');
                (new ExternalSourceController())->create();
            });
            \Flight::route('POST /external-sources/create', function () use ($auth) {
                $auth->requirePermission('external_sources:manage');
                (new ExternalSourceController())->store();
            });
            \Flight::route('GET /external-sources/@id/edit', function (string $id) use ($auth) {
                $auth->requirePermission('external_sources:manage');
                (new ExternalSourceController())->edit($id);
            });
            \Flight::route('POST /external-sources/@id/edit', function (string $id) use ($auth) {
                $auth->requirePermission('external_sources:manage');
                (new ExternalSourceController())->update($id);
            });
            \Flight::route('POST /external-sources/@id/delete', function (string $id) use ($auth) {
                $auth->requirePermission('external_sources:manage');
                (new ExternalSourceController())->destroy($id);
            });
            \Flight::route('POST /external-sources/@id/clear-cache', function (string $id) use ($auth) {
                $auth->requirePermission('external_sources:manage');
                (new ExternalSourceController())->clearCache($id);
            });
            \Flight::route('POST /external-sources/@id/discover-fields', function (string $id) use ($auth) {
                $auth->requirePermission('external_sources:manage');
                (new ExternalSourceController())->discoverFields($id);
            });

            // Themes
            \Flight::route('GET /themes', function () use ($auth) {
                $auth->requireAuth('admin');
                (new ThemeController())->index();
            });
            \Flight::route('POST /themes/activate', function () use ($auth) {
                $auth->requireAuth('admin');
                (new ThemeController())->activate();
            });
            \Flight::route('GET /themes/preview', function () use ($auth) {
                $auth->requireAuth('admin');
                (new ThemeController())->preview();
            });

            // Theme settings
            \Flight::route('GET /theme-settings', function () use ($auth) {
                $auth->requireAuth('admin');
                (new ThemeSettingsController())->index();
            });
            \Flight::route('POST /theme-settings', function () use ($auth) {
                $auth->requireAuth('admin');
                (new ThemeSettingsController())->update();
            });
            \Flight::route('POST /theme-settings/reset', function () use ($auth) {
                $auth->requireAuth('admin');
                (new ThemeSettingsController())->reset();
            });
        }, [AuthMiddleware::class, CsrfMiddleware::class]);

        // Admin internal JSON API (for JS islands). Kept on the per-route
        // pattern because auth failures must return JSON 401/403, not an HTML
        // login redirect. Session auth alone is not enough for mutating calls:
        // cross-origin forms can replay the session cookie, so CSRF is still
        // required on POSTs.
        $auth = new AuthMiddleware();
        $csrf = new CsrfMiddleware();
        \Flight::route('POST /admin/api/media/upload', function () use ($auth, $csrf) {
            $auth->requirePermissionJson('media:upload');
            $csrf->verifyOrFail();
            (new MediaController())->upload();
        });
        \Flight::route('GET /admin/api/media', function () use ($auth) {
            $auth->requireAuthJson();
            (new MediaController())->browse();
        });
        \Flight::route('POST /admin/api/posts/@id/autosave', function (string $id) use ($auth, $csrf) {
            $auth->requireAuthJson();
            $csrf->verifyOrFail();
            (new PostController())->autosave($id);
        });
        \Flight::route('GET /admin/api/oembed', function () use ($auth) {
            $auth->requireAuthJson();
            (new \TypeDock\Admin\OembedController())->resolve();
        });
        \Flight::route('GET /admin/api/ogp', function () use ($auth) {
            // Session auth only (matching /admin/api/oembed). The SSRF guard
            // in OgpController blocks the dangerous shape of abuse — loopback
            // and private-range targets — and the call is read-only.
            $auth->requireAuthJson();
            (new \TypeDock\Admin\OgpController())->resolve();
        });
        \Flight::route('GET /admin/api/link-card', function () use ($auth) {
            $auth->requireAuthJson();
            (new \TypeDock\Admin\LinkCardController())->resolve();
        });
    }

    private function registerApiRoutes(): void
    {
        if (!(bool) config('app.api_enabled', false)) {
            $disabled = function (): void {
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Not found'], JSON_THROW_ON_ERROR);
            };

            foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
                \Flight::route($method . ' /api', $disabled);
                \Flight::route($method . ' /api/*', $disabled);
            }
            return;
        }

        // External REST API (APIKey auth)
        \Flight::route('GET /api/v1/posts', function () {
            (new ApiController())->listPosts();
        });
        \Flight::route('GET /api/v1/posts/@id', function (string $id) {
            (new ApiController())->getPost($id);
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
        // Posts archive slug is configurable via Settings → General. Default
        // is 'blog' for new installs; operators can switch to 'journal',
        // 'news', etc. The value is sanitised on save so the interpolation
        // here cannot introduce path-traversal or regex metacharacters.
        $postsSlug = posts_archive_slug();

        // Posts archive
        \Flight::route('GET /' . $postsSlug, function () {
            (new FrontendController())->blogIndex();
        });
        \Flight::route('GET /' . $postsSlug . '/page/@page', function (string $page) {
            (new FrontendController())->blogIndex((int) $page);
        });

        // Single post
        \Flight::route('GET /' . $postsSlug . '/@slug.md', function (string $slug) {
            (new FrontendController())->blogPostMarkdown($slug);
        });
        \Flight::route('GET /' . $postsSlug . '/@slug', function (string $slug) {
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

        // Author archive
        \Flight::route('GET /author/@slug', function (string $slug) {
            (new FrontendController())->authorArchive($slug);
        });
        \Flight::route('GET /author/@slug/page/@page', function (string $slug, string $page) {
            (new FrontendController())->authorArchive($slug, (int) $page);
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

        // When the homepage is the posts archive, `/page/N` should paginate
        // it. In 'page' mode this route is unused — a static home page is
        // unpaginated — but registering it unconditionally is harmless: the
        // controller re-checks home_mode and delegates appropriately.
        \Flight::route('GET /page/@page', function (string $page) {
            (new FrontendController())->homepage((int) $page);
        });

        // Catchall for static pages (must be last)
        \Flight::route('GET /*', function () {
            (new FrontendController())->page();
        });
    }
}
