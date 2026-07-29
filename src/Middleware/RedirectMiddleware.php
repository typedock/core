<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

use TypeDock\Contract\QueryAwareRedirectResolver;
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
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $uri        = parse_url($requestUri, PHP_URL_PATH);
        $uri        = is_string($uri) && $uri !== '' ? $uri : '/';
        $query      = parse_url($requestUri, PHP_URL_QUERY);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'GET') {
            return;
        }
        if (str_starts_with($uri, '/admin') || str_starts_with($uri, '/api')) {
            return;
        }

        foreach (self::$resolvers as $resolver) {
            // Only resolvers that opt into query semantics see the full
            // request target. Regex and third-party path resolvers retain the
            // historical path-only contract, so `.*` cannot accidentally
            // absorb a tracking query into a replacement capture.
            if ($resolver instanceof QueryAwareRedirectResolver
                && is_string($query)
                && $query !== ''
            ) {
                $result = $resolver->resolveRequestTarget($uri . '?' . $query);
                if ($result !== null && $this->isSafeResult($result)) {
                    $this->send($result);
                }
            }

            // A normal path rule applies regardless of query parameters.
            $result = $resolver->resolve($uri);
            if ($result !== null && $this->isSafeResult($result)) {
                $this->send($result);
            }
        }
    }

    /**
     * Treat resolver output as untrusted at the final header boundary. This
     * protects legacy rows written before validation was added and custom
     * resolvers that do not use the bundled RedirectRuleValidator.
     *
     * @param array{0:string,1:int} $result
     */
    private function isSafeResult(array $result): bool
    {
        [$target, $code] = $result;
        if (!in_array($code, [301, 302, 307, 308], true)) {
            return false;
        }
        if ($target === '' || preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return false;
        }

        if (str_starts_with($target, '/')) {
            // `//host` and `/\host` are interpreted as external authorities by
            // browsers after slash normalization. Local targets use one
            // forward slash and no backslashes.
            return !str_starts_with($target, '//') && !str_contains($target, '\\');
        }

        $parts = parse_url($target);
        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && (string) ($parts['host'] ?? '') !== '';
    }

    /** @param array{0:string,1:int} $result */
    private function send(array $result): never
    {
        [$target, $code] = $result;
        header('Location: ' . $target, true, $code);
        exit;
    }
}
