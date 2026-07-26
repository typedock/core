<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use flight\Engine;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TypeDock\Contract\PluginInterface;
use TypeDock\Core\Database\HranaHttpClient;
use TypeDock\Core\Database\LibsqlPdo;
use TypeDock\Core\PluginContext;
use TypeDock\Core\PluginLoader;
use TypeDock\Core\ServiceProvider;

final class CspManifestTestPlugin implements PluginInterface
{
    public function register(PluginContext $context): void {}

    public function getName(): string
    {
        return 'CSP manifest test';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return [];
    }
}

final class PluginLoaderTest extends TestCase
{
    public function testPluginDbStatesAreLoadedInOneQuery(): void
    {
        $requestCount = 0;
        $request = [];
        $client = new HranaHttpClient(
            'https://example.turso.io',
            'token',
            [],
            static function (string $url, array $headers, string $body) use (&$requestCount, &$request): array {
                $requestCount++;
                $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                return [
                    'status' => 200,
                    'body' => json_encode([
                        'results' => [[
                            'type' => 'ok',
                            'response' => [
                                'type' => 'execute',
                                'result' => [
                                    'cols' => [
                                        ['name' => 'key_name', 'decltype' => 'TEXT'],
                                        ['name' => 'value', 'decltype' => 'TEXT'],
                                    ],
                                    'rows' => [
                                        [
                                            ['type' => 'text', 'value' => 'plugin.form.enabled'],
                                            ['type' => 'text', 'value' => 'true'],
                                        ],
                                        [
                                            ['type' => 'text', 'value' => 'plugin.social.enabled'],
                                            ['type' => 'text', 'value' => 'false'],
                                        ],
                                        [
                                            ['type' => 'text', 'value' => 'plugin.backup.enabled'],
                                            ['type' => 'text', 'value' => '"invalid"'],
                                        ],
                                    ],
                                    'affected_row_count' => 0,
                                    'last_insert_rowid' => null,
                                ],
                            ],
                        ], [
                            'type' => 'ok',
                            'response' => ['type' => 'close'],
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ];
            },
        );
        $pdo = new LibsqlPdo('', '', [], $client);
        \Flight::map('db', static fn(): \PDO => $pdo);

        $method = new ReflectionMethod(PluginLoader::class, 'readDbEnabledFlags');
        $states = $method->invoke(new PluginLoader(), ['form', 'social', 'backup']);

        self::assertSame(1, $requestCount);
        self::assertSame([
            'form' => true,
            'social' => false,
        ], $states);
        self::assertSame(
            'SELECT key_name, value FROM site_options WHERE key_name IN (?, ?, ?)',
            $request['requests'][0]['stmt']['sql'],
        );
        self::assertSame([
            ['type' => 'text', 'value' => 'plugin.form.enabled'],
            ['type' => 'text', 'value' => 'plugin.social.enabled'],
            ['type' => 'text', 'value' => 'plugin.backup.enabled'],
        ], $request['requests'][0]['stmt']['args']);
    }

    public function testEnabledPluginManifestExtendsAdminCspAfterSuccessfulBoot(): void
    {
        \Flight::setEngine(new Engine());
        (new ServiceProvider())->register();
        \Flight::map('db', static fn(): \PDO => new \PDO('sqlite::memory:'));

        $pluginDir = sys_get_temp_dir() . '/typedock-csp-manifest-' . bin2hex(random_bytes(4));
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0775, true);
        }
        $manifestPath = $pluginDir . '/plugin.json';
        $manifest = [
            'slug' => 'typedock-csp-manifest-test',
            'main_class' => CspManifestTestPlugin::class,
            'provides' => [],
            'admin_csp' => [
                'script-src' => ['https://cdn.example.com'],
                'frame-src' => ['https://widgets.example.com'],
            ],
        ];

        try {
            $method = new ReflectionMethod(PluginLoader::class, 'loadDropInPlugin');
            $method->invoke(
                new PluginLoader(),
                'typedock-csp-manifest-test',
                $manifestPath,
                $manifest
            );

            $policy = \Flight::admin_csp()->toHeaderValue();
            self::assertStringContainsString('script-src', $policy);
            self::assertStringContainsString('https://cdn.example.com', $policy);
            self::assertStringContainsString('frame-src', $policy);
            self::assertStringContainsString('https://widgets.example.com', $policy);
        } finally {
            if (is_file($manifestPath)) {
                unlink($manifestPath);
            }
            if (is_dir($pluginDir)) {
                rmdir($pluginDir);
            }
        }
    }
}
