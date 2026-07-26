<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use PDO;
use PHPUnit\Framework\TestCase;
use TypeDock\Core\Database\ConnectionFactory;
use TypeDock\Core\Database\LibsqlPdoStatement;
use TypeDock\Core\Migration\Grammar\SqliteGrammar;
use TypeDock\Core\Migration\Migrator;

final class LibsqlPdoStatementTest extends TestCase
{
    public function testFetchColumnAndAssociativeFetchAllConsumeRowsNormally(): void
    {
        $statement = $this->statement([
            ['id' => 'a', 'title' => 'First'],
            ['id' => 'b', 'title' => 'Second'],
        ]);

        self::assertTrue($statement->execute());
        self::assertSame('a', $statement->fetchColumn());
        self::assertSame(
            [['id' => 'b', 'title' => 'Second']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
        self::assertFalse($statement->fetch());
    }

    public function testDefaultFetchModeDoesNotDuplicateRows(): void
    {
        $statement = $this->statement([
            ['id' => 'a'],
            ['id' => 'b'],
        ]);

        $statement->execute();

        self::assertSame([['id' => 'a'], ['id' => 'b']], $statement->fetchAll());
    }

    public function testPreparedStatementCanBeReusedWithNewParameters(): void
    {
        $calls = [];
        $statement = new LibsqlPdoStatement(
            'SELECT value FROM example WHERE id = ?',
            static function (string $sql, array $params) use (&$calls): array {
                $calls[] = [$sql, $params];
                return [
                    'rows' => [['value' => (string) $params[0]]],
                    'affected' => 0,
                    'columns' => 1,
                ];
            },
        );

        $statement->execute(['first']);
        self::assertSame('first', $statement->fetchColumn());
        $statement->execute(['second']);
        self::assertSame('second', $statement->fetchColumn());

        self::assertSame([
            ['SELECT value FROM example WHERE id = ?', ['first']],
            ['SELECT value FROM example WHERE id = ?', ['second']],
        ], $calls);
    }

    public function testBindingAndStatementIterationFollowPdoConventions(): void
    {
        $received = [];
        $statement = new LibsqlPdoStatement(
            'SELECT id FROM example WHERE enabled = ?',
            static function (string $sql, array $params) use (&$received): array {
                $received = $params;
                return [
                    'rows' => [['id' => 1], ['id' => 2]],
                    'affected' => 0,
                    'columns' => 1,
                ];
            },
        );
        $statement->bindValue(1, true, PDO::PARAM_BOOL);
        $statement->execute();

        self::assertSame([true], $received);
        self::assertSame([['id' => 1], ['id' => 2]], iterator_to_array($statement));
    }

    public function testLibsqlUsesTheSqliteMigrationGrammar(): void
    {
        self::assertSame('sqlite', ConnectionFactory::schemaDriver('libsql'));
        self::assertInstanceOf(SqliteGrammar::class, Migrator::grammarFor('libsql'));
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function statement(array $rows): LibsqlPdoStatement
    {
        return new LibsqlPdoStatement(
            'SELECT * FROM example',
            static fn(): array => [
                'rows' => $rows,
                'affected' => 0,
                'columns' => $rows === [] ? 0 : count($rows[0]),
            ],
            PDO::FETCH_ASSOC,
        );
    }
}
