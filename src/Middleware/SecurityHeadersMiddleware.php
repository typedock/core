<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

use TypeDock\Security\AdminCspPolicy;

/**
 * Adds the OWASP-recommended baseline of HTTP response headers and strips
 * server fingerprinting headers that PHP / nginx emit by default.
 *
 * CSP is emitted only for admin/api routes. Public frontend pages intentionally
 * omit CSP by default so operator-managed analytics, ads, consent managers, and
 * embeds can work without an ever-growing allowlist.
 *
 * The admin policy blocks `object-src`, sets `base-uri` and `form-action` to
 * self, and uses `frame-ancestors 'self'` to mitigate clickjacking. Tightening
 * to nonce-based script-src/style-src is a future hardening step once admin
 * templates are audited for inline scripts.
 */
class SecurityHeadersMiddleware
{
    public function __construct(
        private readonly ?AdminCspPolicy $adminCsp = null,
    ) {}

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

        if ($isAdmin) {
            $policy = $this->adminCsp ?? new AdminCspPolicy();
            header('Content-Security-Policy: ' . $policy->toHeaderValue());
        }
    }
}
