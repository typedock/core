<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

use TypeDock\Contract\RedirectResolver;

/**
 * Runs the redirect resolver chain before each non-admin GET request. Core
 * owns the chain invoker; the individual resolvers are now contributed by
 * plugins (e.g. RedirectPlugin registers ExactMatchResolver + RegexResolver).
 * When no plugin is active the chain is empty and nothing redirects.
 */
class RedirectMiddleware
{
    /** @var RedirectResolver[] */
    private static array $resolvers = [];

    public static function addResolver(RedirectResolver $resolver): void
    {
        self::$resolvers[] = $resolver;
    }

    public function handle(): void
    {
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'GET') {
            return;
        }
        if (str_starts_with($uri, '/admin') || str_starts_with($uri, '/api')) {
            return;
        }

        foreach (self::$resolvers as $resolver) {
            $result = $resolver->resolve($uri);
            if ($result !== null) {
                [$target, $code] = $result;
                header('Location: ' . $target, true, $code);
                exit;
            }
        }
    }
}
