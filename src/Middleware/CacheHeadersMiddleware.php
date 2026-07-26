<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

/**
 * Owns the `Cache-Control` header for every response.
 *
 * PHP's session module stamps `no-store, no-cache, must-revalidate` plus a
 * 1981 `Expires` on any request that opens a session (session.cache_limiter,
 * pinned to `nocache` in typedock_configure_session_cookie()). This class
 * runs before the route and has the final word on the header, so a public
 * page keeps its CDN-friendly value while anything session-bound is
 * downgraded — by markPrivate() on the way out, or by PHP's own limiter if
 * some code opened a session without going through the helper.
 *
 * Policy, in order:
 *   1. Non-GET/HEAD request                          -> private, no-store
 *   2. Request carries the auth or PHP session cookie -> private, no-store
 *   3. Path under /admin, /api or the installer       -> private, no-store
 *   4. Public caching disabled (the default)          -> no header at all
 *   5. Otherwise                                      -> public + s-maxage
 *
 * A request that opens a session while rendering is downgraded after the
 * fact via markPrivate(), which typedock_session_start() calls. That keeps
 * the invariant structural: a response that can carry per-visitor state
 * (a CSRF token, a flash message) is never handed to a shared cache.
 *
 * `max-age` defaults to 0 and `s-maxage` carries the real TTL, so the CDN
 * holds the page while browsers still revalidate — that way a purge or a
 * TTL expiry is visible to visitors immediately, without a hard refresh.
 */
final class CacheHeadersMiddleware
{
    public const PRIVATE_VALUE = 'private, no-store';

    /** Upper bound for any operator-supplied TTL (1 year, per RFC 9111). */
    private const MAX_TTL = 31536000;

    /** Set once a session has been started during this request. */
    private static bool $forcedPrivate = false;

    public function handle(): void
    {
        if (headers_sent() || self::$forcedPrivate) {
            return;
        }

        $path  = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $value = self::decide(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            is_string($path) && $path !== '' ? $path : '/',
            self::hasStateCookie(),
            self::settings(),
        );

        if ($value !== null) {
            header('Cache-Control: ' . $value);
        }
    }

    /**
     * Pure policy decision. Returns the header value, or null when TypeDock
     * should stay out of the way and emit no Cache-Control at all.
     *
     * @param array{enabled:bool, edge_ttl:int, browser_ttl:int, stale_while_revalidate:int} $settings
     */
    public static function decide(string $method, string $path, bool $hasStateCookie, array $settings): ?string
    {
        $method = strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return self::PRIVATE_VALUE;
        }

        if ($hasStateCookie || self::isPrivatePath($path)) {
            return self::PRIVATE_VALUE;
        }

        if (!$settings['enabled']) {
            return null;
        }

        $parts = [
            'public',
            'max-age=' . self::clampTtl($settings['browser_ttl']),
            's-maxage=' . self::clampTtl($settings['edge_ttl']),
        ];

        $stale = self::clampTtl($settings['stale_while_revalidate']);
        if ($stale > 0) {
            $parts[] = 'stale-while-revalidate=' . $stale;
        }

        return implode(', ', $parts);
    }

    /**
     * Force `private, no-store` for the rest of the request and remember the
     * decision so a later handle() call can't upgrade it again.
     */
    public static function markPrivate(): void
    {
        self::$forcedPrivate = true;

        if (headers_sent()) {
            return;
        }

        header('Cache-Control: ' . self::PRIVATE_VALUE);
        // Belt and braces for setups that still have a cache_limiter active.
        header_remove('Pragma');
        header_remove('Expires');
    }

    /**
     * Effective settings: `CACHE_PUBLIC_HEADERS=true` in config forces the
     * feature on (and locks the admin switch); otherwise the site_options
     * row written from Settings -> Cache decides.
     *
     * @return array{enabled:bool, edge_ttl:int, browser_ttl:int, stale_while_revalidate:int, env_locked:bool}
     */
    public static function settings(): array
    {
        $envLocked = (bool) config('cache.public_headers', false);

        return [
            'enabled'    => $envLocked || (bool) site_option('cache.public_headers', false),
            'edge_ttl'   => self::clampTtl(site_option('cache.edge_ttl', config('cache.edge_ttl', 600))),
            'browser_ttl' => self::clampTtl(site_option('cache.browser_ttl', config('cache.browser_ttl', 0))),
            'stale_while_revalidate' => self::clampTtl(
                site_option('cache.stale_while_revalidate', config('cache.stale_while_revalidate', 86400))
            ),
            'env_locked' => $envLocked,
        ];
    }

    /**
     * True when the request carries either the admin auth cookie or the PHP
     * session cookie. Both mean the response may be visitor-specific — and
     * the auth cookie in particular gates the `?preview_theme=` override,
     * which must never reach a shared cache.
     */
    public static function hasStateCookie(): bool
    {
        $names = [
            (string) config('auth.cookie_name', 'typedock_auth'),
            typedock_session_cookie_name(),
        ];

        foreach ($names as $name) {
            if ($name !== '' && ($_COOKIE[$name] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    private static function isPrivatePath(string $path): bool
    {
        if ($path === '/install.php') {
            return true;
        }

        return preg_match('#^/(admin|api)(/|$)#', $path) === 1;
    }

    public static function clampTtl(mixed $value): int
    {
        $ttl = is_numeric($value) ? (int) $value : 0;

        return max(0, min(self::MAX_TTL, $ttl));
    }
}
