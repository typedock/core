<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

/**
 * Adds the OWASP-recommended baseline of HTTP response headers and strips
 * server fingerprinting headers that PHP / nginx emit by default.
 *
 * Two CSP policies are emitted depending on the route prefix:
 *   - admin/api: locked down to same-origin (with `'unsafe-inline'` for the
 *     handful of inline <style> blocks Latte templates carry, and to keep the
 *     plugin iframe shell working on the same origin).
 *   - frontend: more permissive — themes legitimately load fonts/images from
 *     third parties, and oEmbed iframes (YouTube/Vimeo/X/Spotify/SoundCloud)
 *     need https: in `frame-src`.
 *
 * Both policies block `object-src`, set `base-uri` and `form-action` to self,
 * and use `frame-ancestors 'self'` to mitigate clickjacking. Tightening to
 * nonce-based script-src/style-src is a future hardening step once admin
 * templates are audited for inline scripts.
 */
class SecurityHeadersMiddleware
{
    public function handle(): void
    {
        if (headers_sent()) {
            return;
        }

        // PHP-FPM emits `X-Powered-By: PHP/x.y.z` when expose_php=On. The
        // Dockerfile sets it Off in production, but strip it here too so the
        // header is gone regardless of how PHP is configured at the host.
        header_remove('X-Powered-By');

        $uri     = $_SERVER['REQUEST_URI'] ?? '/';
        $isAdmin = str_starts_with($uri, '/admin') || str_starts_with($uri, '/api');

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()');

        // HSTS only over HTTPS. Sending it over plain HTTP is a no-op per the
        // spec but is misleading in scan reports, so gate on the actual scheme.
        if (typedock_is_https()) {
            header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
        }

        header('Content-Security-Policy: ' . ($isAdmin ? self::adminCsp() : self::frontendCsp()));
    }

    private static function adminCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }

    private static function frontendCsp(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https://fonts.gstatic.com",
            "connect-src 'self'",
            "frame-src 'self' https:",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }

}
