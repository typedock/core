<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Middleware\AuthMiddleware;
use TypeDock\Middleware\CsrfMiddleware;
use TypeDock\Middleware\CacheMiddleware;
use TypeDock\Middleware\RedirectMiddleware;

class App
{
    public function run(): void
    {
        $this->configure();
        $this->registerServices();
        $this->loadModulesAndPlugins();
        $this->registerMiddleware();
        $this->registerRoutes();

        \Flight::start();
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

    private function loadModulesAndPlugins(): void
    {
        (new ModuleLoader())->load();
        (new PluginLoader())->load();
    }

    private function registerMiddleware(): void
    {
        // Register before-filter middlewares
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

    private function renderErrorPage(string $type, string $message): void
    {
        try {
            /** @var \TypeDock\Theme\LatteFactory $latteFactory */
            $latteFactory = \Flight::get('latte');
            $latteFactory->render("layouts/{$type}.latte", ['message' => $message]);
        } catch (\Throwable) {
            echo "<h1>Error {$type}</h1><p>" . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
    }
}
