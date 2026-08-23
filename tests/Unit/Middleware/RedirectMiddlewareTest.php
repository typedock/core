<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use TypeDock\Contract\QueryAwareRedirectResolver;
use TypeDock\Contract\RedirectResolver;
use TypeDock\Middleware\RedirectMiddleware;

final class RedirectMiddlewareTest extends TestCase
{
    public function testQuerySpecificTargetIsTriedBeforeThePlainPath(): void
    {
        $resolver = new class implements QueryAwareRedirectResolver {
            /** @var array<int, string> */
            public array $candidates = [];

            public function resolve(string $sourcePath): ?array
            {
                $this->candidates[] = 'path:' . $sourcePath;
                return null;
            }

            public function resolveRequestTarget(string $requestTarget): ?array
            {
                $this->candidates[] = 'query:' . $requestTarget;
                return null;
            }
        };

        RedirectMiddleware::addResolver($resolver);
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_URI'] = '/?p=123';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            (new RedirectMiddleware())->handle();
        } finally {
            if ($previousUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousUri;
            }
            if ($previousMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            }
        }

        $this->assertSame(['query:/?p=123', 'path:/'], $resolver->candidates);
    }

    public function testOrdinaryResolversKeepReceivingOnlyThePath(): void
    {
        $resolver = new class implements RedirectResolver {
            /** @var array<int, string> */
            public array $candidates = [];

            public function resolve(string $sourcePath): ?array
            {
                $this->candidates[] = $sourcePath;
                return null;
            }
        };

        RedirectMiddleware::addResolver($resolver);
        $previousUri = $_SERVER['REQUEST_URI'] ?? null;
        $previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_URI'] = '/old?utm_source=test';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            (new RedirectMiddleware())->handle();
        } finally {
            if ($previousUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $previousUri;
            }
            if ($previousMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $previousMethod;
            }
        }

        $this->assertSame(['/old'], $resolver->candidates);
    }

    public function testUnsafeLegacyResolverOutputIsIgnored(): void
    {
        RedirectMiddleware::addResolver(new class implements RedirectResolver {
            public function resolve(string $sourcePath): ?array
            {
                return ['javascript://alert.example/payload', 301];
            }
        });
        $following = new class implements RedirectResolver {
            public bool $called = false;

            public function resolve(string $sourcePath): ?array
            {
                $this->called = true;
                return null;
            }
        };
        RedirectMiddleware::addResolver($following);

        $_SERVER['REQUEST_URI'] = '/old';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        (new RedirectMiddleware())->handle();

        $this->assertTrue($following->called, 'An unsafe Location must be skipped, not emitted');
    }
}
