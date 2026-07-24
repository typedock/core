<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use TypeDock\Core\Database\HranaHttpClient;
use TypeDock\Core\Database\LibsqlPdo;

final class LibsqlPdoTest extends TestCase
{
    public function testPdoQueryAndExecUseHranaResults(): void
    {
        $responses = [
            $this->response(
                cols: ['id', 'name'],
                rows: [[
                    ['type' => 'integer', 'value' => '42'],
                    ['type' => 'text', 'value' => 'Ada'],
                ]],
            ),
            $this->response(affected: 1, lastInsertId: '43'),
        ];
        $client = new HranaHttpClient(
            'https://example.turso.io',
            'token',
            [],
            static function () use (&$responses): array {
                $response = array_shift($responses);
                self::assertIsArray($response);
                return $response;
            },
        );
        $pdo = new LibsqlPdo('', '', [], $client);

        $statement = $pdo->query('SELECT id, name FROM users');

        self::assertNotFalse($statement);
        self::assertSame(['id' => 42, 'name' => 'Ada'], $statement->fetch());
        self::assertSame(1, $pdo->exec("INSERT INTO users (name) VALUES ('Grace')"));
        self::assertSame('43', $pdo->lastInsertId());
        self::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame('libsql-hrana-http', $pdo->getAttribute(PDO::ATTR_SERVER_VERSION));
    }

    public function testTransactionBuffersWritesIntoOneAtomicBatch(): void
    {
        $request = [];
        $client = new HranaHttpClient(
            'https://example.turso.io',
            'token',
            [],
            static function (string $url, array $headers, string $body) use (&$request): array {
                $request = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                return self::batchResponse([
                    self::statementResult(),
                    self::statementResult(affected: 1, lastInsertId: '44'),
                    self::statementResult(affected: 1),
                    self::statementResult(),
                    null,
                ]);
            },
        );
        $pdo = new LibsqlPdo('', '', [], $client);

        self::assertTrue($pdo->beginTransaction());
        self::assertTrue($pdo->inTransaction());
        self::assertSame(0, $pdo->exec("INSERT INTO users (name) VALUES ('Ada')"));
        $statement = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
        self::assertNotFalse($statement);
        self::assertTrue($statement->execute(['Grace', 44]));
        self::assertTrue($pdo->commit());

        self::assertFalse($pdo->inTransaction());
        self::assertSame('batch', $request['requests'][0]['type']);
        self::assertSame(
            "INSERT INTO users (name) VALUES ('Ada')",
            $request['requests'][0]['batch']['steps'][1]['stmt']['sql'],
        );
        self::assertSame(
            [
                ['type' => 'text', 'value' => 'Grace'],
                ['type' => 'integer', 'value' => '44'],
            ],
            $request['requests'][0]['batch']['steps'][2]['stmt']['args'],
        );
        self::assertSame('44', $pdo->lastInsertId());
    }

    public function testTransactionRejectsReadsAndCanBeRolledBackLocally(): void
    {
        $client = new HranaHttpClient(
            'https://example.turso.io',
            'token',
            [],
            static function (): array {
                self::fail('A rejected or rolled-back transaction must not send an HTTP request.');
            },
        );
        $pdo = new LibsqlPdo('', '', [], $client);
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO users (name) VALUES ('Ada')");

        try {
            $pdo->query('SELECT id FROM users');
            self::fail('Expected a PDOException for a read in a buffered transaction.');
        } catch (PDOException $e) {
            self::assertStringContainsString('write-only statements', $e->getMessage());
        }

        self::assertTrue($pdo->inTransaction());
        self::assertTrue($pdo->rollBack());
        self::assertFalse($pdo->inTransaction());
    }

    /**
     * @param list<string> $cols
     * @param list<list<array<string,string>>> $rows
     * @return array{status:int,body:string}
     */
    private function response(
        array $cols = [],
        array $rows = [],
        int $affected = 0,
        ?string $lastInsertId = null,
    ): array {
        return [
            'status' => 200,
            'body' => json_encode([
                'baton' => null,
                'base_url' => null,
                'results' => [[
                    'type' => 'ok',
                    'response' => [
                        'type' => 'execute',
                        'result' => [
                            'cols' => array_map(
                                static fn(string $name): array => [
                                    'name' => $name,
                                    'decltype' => null,
                                ],
                                $cols,
                            ),
                            'rows' => $rows,
                            'affected_row_count' => $affected,
                            'last_insert_rowid' => $lastInsertId,
                        ],
                    ],
                ], [
                    'type' => 'ok',
                    'response' => ['type' => 'close'],
                ]],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function statementResult(
        int $affected = 0,
        ?string $lastInsertId = null,
    ): array {
        return [
            'cols' => [],
            'rows' => [],
            'affected_row_count' => $affected,
            'last_insert_rowid' => $lastInsertId,
        ];
    }

    /**
     * @param list<array<string,mixed>|null> $stepResults
     * @return array{status:int,body:string}
     */
    private static function batchResponse(array $stepResults): array
    {
        return [
            'status' => 200,
            'body' => json_encode([
                'results' => [[
                    'type' => 'ok',
                    'response' => [
                        'type' => 'batch',
                        'result' => [
                            'step_results' => $stepResults,
                            'step_errors' => array_fill(0, count($stepResults), null),
                        ],
                    ],
                ], [
                    'type' => 'ok',
                    'response' => ['type' => 'close'],
                ]],
            ], JSON_THROW_ON_ERROR),
        ];
    }
}
