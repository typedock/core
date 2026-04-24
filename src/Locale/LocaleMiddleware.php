<?php
declare(strict_types=1);

namespace TypeDock\Locale;

/**
 * LocaleMiddleware — runs before route dispatch:
 *   - Detects the locale from the URL prefix (/ja/...) or Accept-Language.
 *   - Sets the request locale in the LocaleService and rewrites REQUEST_URI
 *     to strip the locale prefix so existing routes keep working.
 */
class LocaleMiddleware
{
    public function __construct(private readonly LocaleService $service) {}

    public function handle(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $query = parse_url($uri, PHP_URL_QUERY);

        // Skip system paths
        if (str_starts_with($path, '/admin') || str_starts_with($path, '/api')) {
            return;
        }

        $accept = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        [$locale, $stripped] = $this->service->resolveFromRequest($path, $accept);

        $this->service->setCurrent($locale);
        \Flight::set('current_locale', $locale);

        if ($stripped !== $path) {
            $_SERVER['REQUEST_URI'] = $stripped . ($query ? ('?' . $query) : '');
        }
    }
}
