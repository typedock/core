<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeDock\Core\Database\HranaBlob;
use TypeDock\Core\Database\HranaHttpClient;

final class HranaHttpClientTest extends TestCase
{
    public function testTursoRequestEncodesValuesAndDecodesRows(): void
    {
        $request = [];
        $client = new HranaHttpClient(
            'libsql://example-org.turso.io',
            'secret-token',
            [],
            static function (string $url, array $headers, string $body) use (&$request): array {
                $request = [
                    'url' => $url,
                    'headers' => $headers,
                    'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
                ];

                return self::httpResponse(self::executeResult(
                    cols: ['id', 'name', 'ratio', 'nothing', 'bytes'],
                    rows: [[
                        ['type' => 'integer', 'value' => '7'],
                        ['type' => 'text', 'value' => 'Ada'],
                        ['type' => 'float', 'value' => '1.5'],
                        ['type' => 'null'],
                        ['type' => 'blob', 'base64' => base64_encode("\x00\xff")],
                    ]],
                ));
            },
        );

        $result = $client->execute(
            'SELECT ?, ?, ?, ?, ?',
            [7, 'Ada', 1.5, null, new HranaBlob("\x00\xff")],
        );

        self::assertSame('https://example-org.turso.io/v2/pipeline', $request['url']);
        self::assertContains('Authorization: Bearer secret-token', $request['headers']);
        self::assertSame([
            ['type' => 'integer', 'value' => '7'],
            ['type' => 'text', 'value' => 'Ada'],
            ['type' => 'float', 'value' => '1.5'],
            ['type' => 'null'],
            ['type' => 'blob', 'base64' => base64_encode("\x00\xff")],
        ], $request['body']['requests'][0]['stmt']['args']);
        self::assertSame(['type' => 'close'], $request['body']['requests'][1]);
        self::assertSame([[
            'id' => 7,
            'name' => 'Ada',
            'ratio' => 1.5,
            'nothing' => null,
            'bytes' => "\x00\xff",
        ]], $result['rows']);
    }

    public function testBunnyPipelineUrlAndNamedParametersAreAccepted(): void
    {
        $request = [];
        $client = new HranaHttpClient(
            'https://database-id.lite.bunnydb.net/v2/pipeline',
            'bunny-token',
            [],
            static function (string $url, array $headers, string $body) use (&$request): array {
                $request = [
                    'url' => $url,
                    'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
                ];
                return self::httpResponse(self::executeResult());
            },
        );

        $client->execute(
            'UPDATE users SET enabled = :enabled WHERE id = @id',
            [':enabled' => true, '@id' => 12],
        );

        self::assertSame(
            'https://database-id.lite.bunnydb.net/v2/pipeline',
            $request['url'],
        );
        self::assertSame([
            ['name' => 'enabled', 'value' => ['type' => 'integer', 'value' => '1']],
            ['name' => 'id', 'value' => ['type' => 'integer', 'value' => '12']],
        ], $request['body']['requests'][0]['stmt']['named_args']);
    }

    public function testAtomicBatchUsesConditionalCommitWithoutBaton(): void
    {
        $request = [];
        $client = new HranaHttpClient(
            'https://database-id.lite.bunnydb.net/v2/pipeline',
            'token',
            [],
            static function (string $url, array $headers, string $body) use (&$request): array {
                $request = [
                    'url' => $url,
                    'body' => json_decode($body, true, 512, JSON_THROW_ON_ERROR),
                ];
                return self::httpResponse(self::batchResult([
                    self::statementResult(),
                    self::statementResult(affected: 1, lastInsertId: '10'),
                    self::statementResult(affected: 1),
                    self::statementResult(),
                    null,
                ]));
            },
        );

        $results = $client->executeAtomicBatch([
            ['sql' => 'INSERT INTO users (name) VALUES (?)', 'params' => ['Ada']],
            ['sql' => 'UPDATE counters SET value = value + 1'],
        ]);

        self::assertSame(
            'https://database-id.lite.bunnydb.net/v2/pipeline',
            $request['url'],
        );
        self::assertArrayNotHasKey('baton', $request['body']);
        self::assertSame('batch', $request['body']['requests'][0]['type']);
        self::assertSame(['type' => 'close'], $request['body']['requests'][1]);

        $steps = $request['body']['requests'][0]['batch']['steps'];
        self::assertSame('BEGIN', $steps[0]['stmt']['sql']);
        self::assertSame([
            'type' => 'and',
            'conds' => [['type' => 'ok', 'step' => 0]],
        ], $steps[1]['condition']);
        self::assertSame([
            'type' => 'and',
            'conds' => [
                ['type' => 'ok', 'step' => 0],
                ['type' => 'ok', 'step' => 1],
            ],
        ], $steps[2]['condition']);
        self::assertSame('COMMIT', $steps[3]['stmt']['sql']);
        self::assertSame([
            'type' => 'and',
            'conds' => [
                ['type' => 'ok', 'step' => 0],
                ['type' => 'not', 'cond' => ['type' => 'ok', 'step' => 3]],
            ],
        ], $steps[4]['condition']);
        self::assertSame('ROLLBACK', $steps[4]['stmt']['sql']);
        self::assertSame(1, $results[0]['affected']);
        self::assertSame('10', $client->lastInsertId());
    }

    public function testAtomicBatchReportsWriteErrorAfterConfirmedRollback(): void
    {
        $client = new HranaHttpClient(
            'https://database-id.lite.bunnydb.net/v2/pipeline',
            'token',
            [],
            static fn(): array => self::httpResponse(self::batchResult(
                [
                    self::statementResult(),
                    self::statementResult(affected: 1),
                    null,
                    null,
                    self::statementResult(),
                ],
                [
                    null,
                    null,
                    ['message' => 'constraint failed', 'code' => 'SQLITE_CONSTRAINT'],
                    null,
                    null,
                ],
            )),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'atomic batch failed at statement 2: SQLITE_CONSTRAINT: constraint failed'
        );
        $client->executeAtomicBatch([
            ['sql' => 'INSERT INTO users (name) VALUES (?)', 'params' => ['Ada']],
            ['sql' => 'INSERT INTO users (name) VALUES (?)', 'params' => ['Ada']],
        ]);
    }

    public function testAtomicBatchRejectsUnconfirmedRollback(): void
    {
        $client = new HranaHttpClient(
            'https://database-id.lite.bunnydb.net/v2/pipeline',
            'token',
            [],
            static fn(): array => self::httpResponse(self::batchResult(
                [self::statementResult(), null, null, null],
                [
                    null,
                    ['message' => 'failed', 'code' => 'SQLITE_ERROR'],
                    null,
                    null,
                ],
            )),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ROLLBACK was not confirmed');
        $client->executeAtomicBatch([['sql' => 'BROKEN SQL']]);
    }

    public function testRejectsPlainHttpOutsideLoopback(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires HTTPS');
        new HranaHttpClient('http://example.com', 'token');
    }

    /**
     * @param list<string> $cols
     * @param list<list<array<string,string>>> $rows
     * @return array<string,mixed>
     */
    private static function executeResult(
        array $cols = [],
        array $rows = [],
    ): array {
        return [
            'results' => [[
                'type' => 'ok',
                'response' => [
                    'type' => 'execute',
                    'result' => self::statementResult($cols, $rows),
                ],
            ]],
        ];
    }

    /**
     * @param list<string> $cols
     * @param list<list<array<string,string>>> $rows
     * @return array<string,mixed>
     */
    private static function statementResult(
        array $cols = [],
        array $rows = [],
        int $affected = 0,
        ?string $lastInsertId = null,
    ): array {
        return [
            'cols' => array_map(
                static fn(string $name): array => ['name' => $name, 'decltype' => null],
                $cols,
            ),
            'rows' => $rows,
            'affected_row_count' => $affected,
            'last_insert_rowid' => $lastInsertId,
        ];
    }

    /**
     * @param list<array<string,mixed>|null> $stepResults
     * @param list<array<string,string>|null> $stepErrors
     * @return array<string,mixed>
     */
    private static function batchResult(array $stepResults, array $stepErrors = []): array
    {
        if ($stepErrors === []) {
            $stepErrors = array_fill(0, count($stepResults), null);
        }

        return [
            'results' => [[
                'type' => 'ok',
                'response' => [
                    'type' => 'batch',
                    'result' => [
                        'step_results' => $stepResults,
                        'step_errors' => $stepErrors,
                    ],
                ],
            ], [
                'type' => 'ok',
                'response' => ['type' => 'close'],
            ]],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,body:string}
     */
    private static function httpResponse(array $payload): array
    {
        return [
            'status' => 200,
            'body' => json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }
}
