<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\Database\HranaHttpClient;
use TypeDock\Core\Database\LibsqlPdo;
use TypeDock\Plugin\Redirect\ExactMatchResolver;
use TypeDock\Plugin\Redirect\RegexPattern;
use TypeDock\Plugin\Redirect\RegexResolver;
use TypeDock\Plugin\Redirect\RedirectImport;

/**
 * Bulk redirect loading: the file describes the desired set of rules, and a
 * row that cannot become a working rule is reported rather than stored.
 */
final class RedirectImportTest extends TestCase
{
    private \PDO $pdo;
    private RedirectImport $import;

    /** @var array<int, string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec(
            'CREATE TABLE redirects (
                id VARCHAR(36) PRIMARY KEY,
                source_path VARCHAR(2000) NOT NULL,
                target_url VARCHAR(2000) NOT NULL,
                status_code INTEGER NOT NULL DEFAULT 301,
                created_at DATETIME NOT NULL
            )'
        );

        $this->import = new RedirectImport($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
    }

    public function testCsvWithAHeaderRow(): void
    {
        $result = $this->importCsv(
            "from,to,status\n/old-page,/new-page,301\n/legacy,https://docs.example.com/,308\n"
        );

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(
            ['/legacy' => ['https://docs.example.com/', 308], '/old-page' => ['/new-page', 301]],
            $this->storedRules()
        );
    }

    public function testCsvWithoutAHeaderRowIsReadPositionally(): void
    {
        $result = $this->importCsv("/old-page,/new-page\n");

        $this->assertSame(1, $result['created']);
        $this->assertSame(['/old-page' => ['/new-page', 301]], $this->storedRules(), 'Status defaults to 301');
    }

    public function testAlternateColumnNamesAreAccepted(): void
    {
        $this->importCsv("source_path,target_url,status_code\n/a-page,/b-page,302\n");

        $this->assertSame(['/a-page' => ['/b-page', 302]], $this->storedRules());
    }

    public function testExcelByteOrderMarkDoesNotEatTheHeader(): void
    {
        $this->importCsv("\xEF\xBB\xBFfrom,to\n/old-page,/new-page\n");

        $this->assertSame(['/old-page' => ['/new-page', 301]], $this->storedRules());
    }

    public function testJsonArrayOfObjects(): void
    {
        $result = $this->importJson([
            ['from' => '/old-page', 'to' => '/new-page', 'status' => 302],
            ['source' => '/other', 'target' => '/elsewhere'],
        ]);

        $this->assertSame(2, $result['created']);
        $this->assertSame(
            ['/old-page' => ['/new-page', 302], '/other' => ['/elsewhere', 301]],
            $this->storedRules()
        );
    }

    public function testJsonThatIsNotAListIsRejectedWithAUsefulMessage(): void
    {
        $file = $this->tmpFile('{"redirects": []}', 'json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JSON array/');

        $this->import->importFile($file, basename($file));
    }

    public function testReimportingTheSameFileUpdatesInsteadOfDuplicating(): void
    {
        $this->importCsv("from,to\n/old-page,/new-page\n");
        $result = $this->importCsv("from,to,status\n/old-page,/somewhere-else,302\n");

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(['/old-page' => ['/somewhere-else', 302]], $this->storedRules());
    }

    public function testAbsoluteSourceUrlIsReducedToItsPath(): void
    {
        $this->importCsv("from,to\nhttps://old.example.com/deep/page/,/new-page\n");

        $this->assertSame(['/deep/page/' => ['/new-page', 301]], $this->storedRules());
    }

    public function testQueryOnlyPermalinkKeepsItsQuery(): void
    {
        // `/?p=123` is identified by its query; storing the bare path would
        // build a rule that redirects the site's own home page.
        $this->importCsv("from,to\nhttps://old.example.com/?p=123,/blog/hello\n");

        $this->assertSame(['/?p=123' => ['/blog/hello', 301]], $this->storedRules());
        $resolver = new ExactMatchResolver($this->pdo);
        $this->assertSame(['/blog/hello', 301], $resolver->resolveRequestTarget('/?p=123'));
        $this->assertNull($resolver->resolve('/'), 'The query rule must not hijack the site root');
    }

    public function testRelativeTargetGetsALeadingSlash(): void
    {
        $this->importCsv("from,to\nold-page,new-page\n");

        $this->assertSame(['/old-page' => ['/new-page', 301]], $this->storedRules());
    }

    public function testRegexRuleSurvivesItsBackslashes(): void
    {
        $result = $this->importCsv("from,to\n~^/old/(\\d+)$,/new/\$1\n");

        $this->assertSame(0, $result['skipped'], implode(' ', $result['errors']));
        $this->assertSame(['~^/old/(\\d+)$' => ['/new/$1', 301]], $this->storedRules());
    }

    public function testUncompilableRegexIsRefusedRatherThanStoredDead(): void
    {
        $result = $this->importCsv("from,to\n~^/old/(unclosed,/new\n");

        $this->assertSame(1, $result['skipped']);
        $this->assertSame([], $this->storedRules());
        $this->assertStringContainsString('regular expression', $result['errors'][0]);
    }

    public function testRegexLengthIsBounded(): void
    {
        $result = $this->importJson([
            ['from' => '~' . str_repeat('a', RegexPattern::MAX_BYTES + 1), 'to' => '/new'],
        ]);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame([], $this->storedRules());
        $this->assertStringContainsString('safe complexity', $result['errors'][0]);
    }

    public function testRegexRuntimeCarriesPcreWorkLimits(): void
    {
        $regex = RegexPattern::compile('^(a+)+$');

        $this->assertNotNull($regex);
        $this->assertStringContainsString('(*LIMIT_MATCH=', $regex);
        $this->assertStringContainsString('(*LIMIT_DEPTH=', $regex);
        $this->assertFalse(@preg_match($regex, str_repeat('a', 5000) . '!'));
        $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    public function testRegexRuleCountIsBounded(): void
    {
        $rows = [];
        for ($i = 0; $i <= RegexPattern::MAX_RULES; $i++) {
            $rows[] = ['from' => '~^/old-' . $i . '$', 'to' => '/new-' . $i];
        }

        $result = $this->importJson($rows);

        $this->assertSame(RegexPattern::MAX_RULES, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('regular-expression rules', $result['errors'][0]);
    }

    public function testRegexResolverSkipsUnsafeLegacyPatterns(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO redirects (id, source_path, target_url, status_code, created_at)
             VALUES (?, ?, ?, 301, ?)'
        );
        $insert->execute([
            'unsafe',
            '~' . str_repeat('a', RegexPattern::MAX_BYTES + 1),
            '/must-not-run',
            '2026-01-01 00:00:00',
        ]);
        $insert->execute([
            'safe',
            '~^/wanted$',
            '/resolved',
            '2026-01-01 00:00:01',
        ]);

        $this->assertSame(
            ['/resolved', 301],
            (new RegexResolver($this->pdo))->resolve('/wanted'),
        );
    }

    public function testRegexResolverNeverEvaluatesBeyondTheSiteLimit(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO redirects (id, source_path, target_url, status_code, created_at)
             VALUES (?, ?, ?, 301, ?)'
        );
        for ($i = 0; $i < RegexPattern::MAX_RULES; $i++) {
            $insert->execute([
                'bounded-' . $i,
                '~^/does-not-match-' . $i . '$',
                '/never',
                sprintf('%06d', $i),
            ]);
        }
        $insert->execute([
            'over-limit',
            '~^/wanted$',
            '/must-not-run',
            sprintf('%06d', RegexPattern::MAX_RULES),
        ]);

        $this->assertNull((new RegexResolver($this->pdo))->resolve('/wanted'));
    }

    public function testUnsupportedStatusCodeIsReportedNotSilentlyCoerced(): void
    {
        $result = $this->importCsv("from,to,status\n/old-page,/new-page,404\n");

        $this->assertSame(1, $result['skipped']);
        $this->assertSame([], $this->storedRules());
        $this->assertStringContainsString('404', $result['errors'][0]);
    }

    public function testSelfRedirectIsRefused(): void
    {
        $result = $this->importCsv("from,to\n/same,/same\n");

        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('loop', $result['errors'][0]);
    }

    public function testNonHttpAbsoluteTargetIsRefused(): void
    {
        $result = $this->importJson([
            ['from' => '/old', 'to' => 'javascript://alert.example/payload'],
        ]);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame([], $this->storedRules());
        $this->assertStringContainsString('http or https', $result['errors'][0]);
    }

    public function testControlCharactersAndOversizeValuesAreRefused(): void
    {
        $result = $this->importJson([
            ['from' => '/header', 'to' => "/new\r\nX-Injected: yes"],
            ['from' => '/' . str_repeat('a', 2001), 'to' => '/new'],
        ]);

        $this->assertSame(2, $result['skipped']);
        $this->assertSame([], $this->storedRules());
        $this->assertStringContainsString('control character', $result['errors'][0]);
        $this->assertStringContainsString('2000-character', $result['errors'][1]);
    }

    public function testIncompleteRowIsSkippedWithoutStoppingTheRest(): void
    {
        $result = $this->importCsv("from,to\n/only-a-source,\n\n/good,/fine\n");

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped'], 'The blank line is not a row');
        $this->assertSame(['/good' => ['/fine', 301]], $this->storedRules());
        $this->assertStringStartsWith('Line 2:', $result['errors'][0]);
    }

    public function testTheSameSourceTwiceInOneFileEndsAsOneRule(): void
    {
        // The second occurrence has to update the row this run just inserted,
        // which it can only do from the in-memory map — the row is not
        // readable yet under a driver that buffers the transaction.
        $result = $this->importCsv("from,to\n/dup,/first\n/dup,/second\n");

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(['/dup' => ['/second', 301]], $this->storedRules());
    }

    public function testRunsOnTheRemoteLibsqlDriver(): void
    {
        // That driver buffers a transaction into one atomic HTTP batch and
        // throws on any read issued inside it, so an importer that looks a row
        // up per line cannot work there. Driving the real LibsqlPdo is the
        // only way to keep that from regressing.
        $sent = [];
        $client = new HranaHttpClient(
            'https://example.turso.io',
            'token',
            [],
            static function (string $url, array $headers, string $body) use (&$sent): array {
                $sent[] = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                return self::hranaResponse(end($sent));
            },
        );
        $pdo = new LibsqlPdo('', '', [], $client);

        $result = (new RedirectImport($pdo))->importFile(
            $this->tmpFile("from,to\n/old-page,/new-page\n/other,/elsewhere\n", 'csv'),
            'r.csv'
        );

        $this->assertSame(2, $result['created']);

        // First request is the lookup, outside any transaction; the writes
        // then travel as one batch.
        $this->assertSame('execute', $sent[0]['requests'][0]['type']);
        $this->assertStringStartsWith('SELECT', $sent[0]['requests'][0]['stmt']['sql']);
        $this->assertSame('batch', $sent[1]['requests'][0]['type']);

        $written = array_values(array_filter(array_map(
            static fn (array $step): string => $step['stmt']['sql'] ?? '',
            $sent[1]['requests'][0]['batch']['steps']
        ), static fn (string $sql): bool => str_starts_with($sql, 'INSERT')));

        $this->assertCount(2, $written);
    }

    public function testFileSizeLimitIsEnforcedBeforeParsing(): void
    {
        $file = $this->tmpFile(str_repeat('x', RedirectImport::MAX_FILE_BYTES + 1), 'json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2 MB');

        $this->import->importFile($file, basename($file));
    }

    public function testRowLimitIsEnforcedBeforeAnyWrite(): void
    {
        $rows = array_fill(0, RedirectImport::MAX_ROWS + 1, [
            'from' => '/same',
            'to'   => '/target',
        ]);
        $file = $this->tmpFile((string) json_encode($rows), 'json');

        try {
            $this->import->importFile($file, basename($file));
            $this->fail('Expected an oversized rule list to be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString((string) RedirectImport::MAX_ROWS, str_replace(',', '', $e->getMessage()));
        }

        $this->assertSame([], $this->storedRules(), 'Preflight limits must run before the first batch commits');
    }

    // -----------------------------------------------------------------

    /**
     * Answer whichever shape the driver asked for: a plain execute, or a batch
     * whose step count has to match what was sent.
     *
     * @param  array<string, mixed> $request
     * @return array{status:int, body:string}
     */
    private static function hranaResponse(array $request): array
    {
        $first = $request['requests'][0];

        if (($first['type'] ?? '') === 'batch') {
            $steps = array_fill(0, count($first['batch']['steps']), [
                'cols' => [],
                'rows' => [],
                'affected_row_count' => 1,
                'last_insert_rowid' => null,
            ]);
            $response = [
                'type'   => 'batch',
                'result' => [
                    'step_results' => $steps,
                    'step_errors'  => array_fill(0, count($steps), null),
                ],
            ];
        } else {
            $response = [
                'type'   => 'execute',
                'result' => [
                    'cols' => [
                        ['name' => 'id', 'decltype' => null],
                        ['name' => 'source_path', 'decltype' => null],
                    ],
                    'rows' => [],
                    'affected_row_count' => 0,
                    'last_insert_rowid'  => null,
                ],
            ];
        }

        return [
            'status' => 200,
            'body'   => json_encode([
                'baton'    => null,
                'base_url' => null,
                'results'  => [
                    ['type' => 'ok', 'response' => $response],
                    ['type' => 'ok', 'response' => ['type' => 'close']],
                ],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array{created:int, updated:int, skipped:int, errors:array<int, string>} */
    private function importCsv(string $contents): array
    {
        $file = $this->tmpFile($contents, 'csv');

        return $this->import->importFile($file, basename($file));
    }

    /**
     * @param  array<int, array<string, mixed>> $rows
     * @return array{created:int, updated:int, skipped:int, errors:array<int, string>}
     */
    private function importJson(array $rows): array
    {
        $file = $this->tmpFile((string) json_encode($rows), 'json');

        return $this->import->importFile($file, basename($file));
    }

    private function tmpFile(string $contents, string $extension): string
    {
        $path = sys_get_temp_dir() . '/typedock-redirects-' . bin2hex(random_bytes(6)) . '.' . $extension;
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;

        return $path;
    }

    /** @return array<string, array{0:string, 1:int}> source => [target, status] */
    private function storedRules(): array
    {
        $rules = [];
        foreach ($this->pdo->query('SELECT * FROM redirects ORDER BY source_path')->fetchAll() as $row) {
            $rules[(string) $row['source_path']] = [(string) $row['target_url'], (int) $row['status_code']];
        }

        return $rules;
    }
}
