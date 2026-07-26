<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use TypeDock\Middleware\CacheHeadersMiddleware;

/**
 * Policy tests for the Cache-Control decision. decide() is pure, so the
 * matrix that matters — who may be handed to a shared cache — is covered
 * without booting Flight or touching headers.
 */
class CacheHeadersTest extends TestCase
{
    /** @return array{enabled:bool, edge_ttl:int, browser_ttl:int, stale_while_revalidate:int} */
    private function settings(bool $enabled = true, int $edge = 600, int $browser = 0, int $stale = 86400): array
    {
        return [
            'enabled'                => $enabled,
            'edge_ttl'               => $edge,
            'browser_ttl'            => $browser,
            'stale_while_revalidate' => $stale,
        ];
    }

    public function testPublicPageGetsSharedCacheHeaderWhenEnabled(): void
    {
        $this->assertSame(
            'public, max-age=0, s-maxage=600, stale-while-revalidate=86400',
            CacheHeadersMiddleware::decide('GET', '/blog/hello', false, $this->settings())
        );
    }

    public function testDisabledFeatureSendsNoHeaderOnPublicPages(): void
    {
        $this->assertNull(
            CacheHeadersMiddleware::decide('GET', '/blog/hello', false, $this->settings(enabled: false))
        );
    }

    public function testStaleWhileRevalidateIsOmittedWhenZero(): void
    {
        $this->assertSame(
            'public, max-age=120, s-maxage=600',
            CacheHeadersMiddleware::decide('GET', '/', false, $this->settings(browser: 120, stale: 0))
        );
    }

    /**
     * A request carrying the auth or PHP session cookie is visitor-specific:
     * admin theme preview rides on that cookie, as do CSRF tokens.
     */
    public function testSessionCookieForcesPrivate(): void
    {
        $this->assertSame(
            CacheHeadersMiddleware::PRIVATE_VALUE,
            CacheHeadersMiddleware::decide('GET', '/', true, $this->settings())
        );
    }

    /**
     * @dataProvider privatePathProvider
     */
    public function testPrivatePathsAreNeverShared(string $path): void
    {
        $this->assertSame(
            CacheHeadersMiddleware::PRIVATE_VALUE,
            CacheHeadersMiddleware::decide('GET', $path, false, $this->settings()),
            $path . ' must not be offered to a shared cache'
        );
    }

    /** @return array<string, array{string}> */
    public static function privatePathProvider(): array
    {
        return [
            'admin root'   => ['/admin'],
            'admin page'   => ['/admin/settings/cache'],
            'api root'     => ['/api'],
            'api endpoint' => ['/api/v1/posts'],
            'installer'    => ['/install.php'],
        ];
    }

    /** A slug that merely starts with the same letters is still public. */
    public function testLookalikePathsStayPublic(): void
    {
        $this->assertStringStartsWith(
            'public,',
            (string) CacheHeadersMiddleware::decide('GET', '/administration-guide', false, $this->settings())
        );
        $this->assertStringStartsWith(
            'public,',
            (string) CacheHeadersMiddleware::decide('GET', '/apiary', false, $this->settings())
        );
    }

    /** @dataProvider mutatingMethodProvider */
    public function testMutatingMethodsAreNeverShared(string $method): void
    {
        $this->assertSame(
            CacheHeadersMiddleware::PRIVATE_VALUE,
            CacheHeadersMiddleware::decide($method, '/contact', false, $this->settings())
        );
    }

    /** @return array<string, array{string}> */
    public static function mutatingMethodProvider(): array
    {
        return [
            'post'   => ['POST'],
            'put'    => ['PUT'],
            'patch'  => ['PATCH'],
            'delete' => ['DELETE'],
        ];
    }

    public function testHeadIsTreatedLikeGet(): void
    {
        $this->assertStringStartsWith(
            'public,',
            (string) CacheHeadersMiddleware::decide('head', '/', false, $this->settings())
        );
    }

    public function testTtlsAreClamped(): void
    {
        $this->assertSame(0, CacheHeadersMiddleware::clampTtl(-1));
        $this->assertSame(0, CacheHeadersMiddleware::clampTtl('not a number'));
        $this->assertSame(31536000, CacheHeadersMiddleware::clampTtl(99999999999));
        $this->assertSame(600, CacheHeadersMiddleware::clampTtl('600'));

        $this->assertSame(
            'public, max-age=0, s-maxage=31536000',
            CacheHeadersMiddleware::decide('GET', '/', false, $this->settings(edge: 99999999, browser: -5, stale: -1))
        );
    }

    public function testSessionCookieNameIsTypeDockSpecific(): void
    {
        $this->assertSame('typedock_session', typedock_session_cookie_name());
    }

    public function testInvalidSessionCookieNameFallsBackToDefault(): void
    {
        $previous = getenv('SESSION_NAME');
        putenv('SESSION_NAME=not-a-valid-name');
        $_ENV['SESSION_NAME'] = 'not-a-valid-name';

        try {
            $this->assertSame('typedock_session', typedock_session_cookie_name());
        } finally {
            unset($_ENV['SESSION_NAME']);
            if ($previous === false) {
                putenv('SESSION_NAME');
            } else {
                putenv('SESSION_NAME=' . $previous);
            }
        }
    }
}
