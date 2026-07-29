<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use flight\Engine;
use PHPUnit\Framework\TestCase;
use TypeDock\Auth\PermissionChecker;
use TypeDock\Core\PluginContext;
use TypeDock\Exception\ForbiddenException;

final class PluginContextAuthorizationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['typedock_auth'], $_GET['_iframed']);
    }

    public function testPluginAdminRoutesAreAdminOnlyByDefault(): void
    {
        $called = false;
        $route = $this->routeFor(['id' => 'user-1', 'role' => 'contributor'], function () use (&$called): void {
            $called = true;
        });

        try {
            ($route->callback)();
            $this->fail('A contributor must not enter a default plugin admin route.');
        } catch (ForbiddenException) {
            $this->assertFalse($called);
        }
    }

    public function testPluginMayExplicitlyGrantItsManagementPermission(): void
    {
        $called = false;
        $route = $this->routeFor(
            ['id' => 'user-1', 'role' => 'editor'],
            function () use (&$called): void {
                $called = true;
            },
            'redirects:manage',
        );

        ($route->callback)();

        $this->assertTrue($called);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function routeFor(array $user, callable $handler, string $permission = 'role:admin'): \flight\net\Route
    {
        \Flight::setEngine(new Engine());
        \Flight::map('session', static fn(): object => new class($user) {
            /** @param array<string, mixed> $user */
            public function __construct(private readonly array $user) {}

            /** @return array<string, mixed> */
            public function check(string $token): array
            {
                return $this->user;
            }
        });
        \Flight::map('permissions', static fn(): PermissionChecker => new PermissionChecker());

        $_COOKIE['typedock_auth'] = 'session-token';
        $_GET['_iframed'] = '1';

        $pdo = new \PDO('sqlite::memory:');
        $context = new PluginContext('example', $pdo);
        $context->registerAdminRoute('GET', '', $handler, permission: $permission);

        return \Flight::router()->getRoutes()[0];
    }
}
