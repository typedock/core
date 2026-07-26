<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Middleware\CacheHeadersMiddleware;
use TypeDock\Middleware\RedirectMiddleware;
use TypeDock\Middleware\SecurityHeadersMiddleware;

class App
{
    public function run(): void
    {
        $this->configure();
        $this->registerServices();
        $this->bootTheme();
        $this->loadPlugins();
        $this->registerMiddleware();
        $this->registerRoutes();

        \Flight::start();
    }

    /**
     * Resolve the active theme from site_options and apply any admin preview override.
     *
     * Preview is intentionally narrow: the `preview_theme` query parameter is
     * honoured only when the requester is an authenticated admin (checked via
     * the same session cookie used by /admin). Unauthenticated visitors always
     * see the persisted theme. Failures here fall silently back to 'default'
     * — e.g. before install has run, `site_options` doesn't exist yet.
     */
    private function bootTheme(): void
    {
        try {
            $loader = new \TypeDock\Theme\ThemeLoader();
            $active = $loader->resolveActiveTheme(\Flight::db());

            $preview = isset($_GET['preview_theme']) ? (string) $_GET['preview_theme'] : '';
            if ($preview !== '' && $loader->themeExists($preview) && $this->previewAllowed()) {
                $active = $preview;
            }

            \Flight::latte()->setActiveTheme($active);
            \Flight::theme_settings()->setActiveTheme($active);

            // Register the active theme's custom components. Done here so it
            // runs after core components are registered (via ServiceProvider)
            // and before routes dispatch, ensuring admin slot pickers and
            // frontend renders both see the full set.
            (new \TypeDock\Component\ThemeComponentRegistrar(TYPEDOCK_ROOT . '/themes'))
                ->registerForTheme($active, \Flight::components());
        } catch (\Throwable) {
            // Keep the factory's baked-in 'default' fallback on any failure.
        }
    }

    private function previewAllowed(): bool
    {
        try {
            $cookieName = (string) config('auth.cookie_name', 'typedock_auth');
            $token      = $_COOKIE[$cookieName] ?? '';
            if ($token === '') {
                return false;
            }
            $user = \Flight::session()->check($token);
            return $user !== null && ($user['role'] ?? '') === 'admin';
        } catch (\Throwable) {
            return false;
        }
    }

    private function configure(): void
    {
        date_default_timezone_set((string) config('app.timezone', 'Asia/Tokyo'));

        $debug = (bool) config('app.debug', false);
        \Flight::set('flight.log_errors', $debug);
        \Flight::set('flight.handle_errors', true);

        // Custom error handler
        \Flight::map('error', function (\Throwable $e): void {
            $this->handleError($e);
        });

        \Flight::map('notFound', function (): void {
            $this->handleNotFound();
        });
    }

    private function registerServices(): void
    {
        (new ServiceProvider())->register();
    }

    private function loadPlugins(): void
    {
        (new PluginLoader())->load();
    }

    private function registerMiddleware(): void
    {
        // Security headers first so every response — including redirects and
        // error pages emitted by later middleware — carries them.
        \Flight::before('start', function (): void {
            (new SecurityHeadersMiddleware())->handle();
        });

        // Cache-Control policy. Registered right after the security headers
        // so redirects and error pages emitted downstream carry it too; any
        // route that opens a session downgrades it to no-store afterwards.
        \Flight::before('start', function (): void {
            (new CacheHeadersMiddleware())->handle();
        });

        // Locale resolution: off by default, opt in via config/app.php
        // (`locale.routing_enabled` = true). When enabled, URL prefixes like
        // /ja/about are stripped before dispatch so existing routes keep
        // working for the localised variant.
        if ((bool) config('app.locale_routing_enabled', false)) {
            \Flight::before('start', function (): void {
                (new \TypeDock\Locale\LocaleMiddleware(\Flight::locales()))->handle();
            });
        }

        \Flight::before('start', function () {
            // Run redirect checks
            (new RedirectMiddleware())->handle();
        });
    }

    private function registerRoutes(): void
    {
        (new Router())->register();
    }

    private function handleError(\Throwable $e): void
    {
        $code    = $e->getCode();
        $status  = match ($code) {
            403 => 403,
            404 => 404,
            422 => 422,
            default => 500,
        };

        http_response_code($status);
        CacheHeadersMiddleware::markPrivate();

        if ($status === 403) {
            $this->renderErrorPage('403', $e->getMessage());
        } elseif ($status === 404) {
            $this->renderErrorPage('404', $e->getMessage());
        } else {
            if ((bool) config('app.debug', false)) {
                DebugRenderer::render($e);
            } else {
                error_log('[TypeDock] ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
                $this->renderErrorPage('500', 'Internal Server Error');
            }
        }
    }

    private function handleNotFound(): void
    {
        http_response_code(404);
        $this->renderErrorPage('404', 'Page not found');
    }

    /**
     * Error responses are never handed to a shared cache. A 404 today is
     * often a page that gets published tomorrow, and caching it at the edge
     * would outlive the fix by a whole TTL.
     */
    private function renderErrorPage(string $type, string $message): void
    {
        CacheHeadersMiddleware::markPrivate();

        try {
            /** @var \TypeDock\Theme\LatteFactory $latteFactory */
            $latteFactory = \Flight::get('latte');
            $latteFactory->render("layouts/{$type}.latte", ['message' => $message]);
        } catch (\Throwable) {
            echo "<h1>Error {$type}</h1><p>" . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
    }
}
