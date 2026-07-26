<?php
declare(strict_types=1);

namespace TypeDock\Core;

class ErrorPage
{
    public static function render(string $type, string $message): void
    {
        // Same rule as App::renderErrorPage() — error responses stay out of
        // shared caches even when public cache headers are enabled.
        \TypeDock\Middleware\CacheHeadersMiddleware::markPrivate();

        try {
            (new \TypeDock\Frontend\FrontendController())->renderErrorPage($type, $message);
            return;
        } catch (\Throwable $e) {
            error_log('[TypeDock] ErrorPage render failed: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
        echo "<!doctype html><meta charset=utf-8><title>Error {$type}</title><h1>Error {$type}</h1><p>"
            . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
}
