<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TypeDock\Core\Database\HranaHttpClient;
use TypeDock\Core\Database\LibsqlPdo;
use TypeDock\Core\PluginLoader;

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
}
