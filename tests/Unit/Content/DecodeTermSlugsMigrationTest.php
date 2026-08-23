<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\Migration\Grammar\SqliteGrammar;
use TypeDock\Core\Migration\Schema;

/**
 * The repair pass for slugs written by the old percent-encoding TermSlugger.
 *
 * Those rows are unreachable — nothing decodes a request path back into
 * `%E3%81%8A…` — so an upgrade has to rewrite them or the site's Japanese term
 * archives stay 404 forever.
 */
final class DecodeTermSlugsMigrationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        require_once TYPEDOCK_ROOT . '/migrations/20240001000021_DecodeTermSlugs.php';

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        foreach (['categories', 'tags'] as $table) {
            $this->pdo->exec(
                "CREATE TABLE {$table} (
                    id TEXT PRIMARY KEY,
                    slug TEXT NOT NULL,
                    locale TEXT NOT NULL DEFAULT 'en',
                    UNIQUE (slug, locale)
                )"
            );
        }
    }

    private function migrate(): void
    {
        (new \DecodeTermSlugs())->up(new Schema($this->pdo, 'sqlite', new SqliteGrammar()));
    }

    /** @return array<int, string> */
    private function slugs(string $table): array
    {
        return $this->pdo->query("SELECT slug FROM {$table} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testEncodedSlugsBecomeReachable(): void
    {
        $this->pdo->exec("INSERT INTO categories (id, slug) VALUES ('1', '%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B')");
        $this->pdo->exec("INSERT INTO tags (id, slug) VALUES ('1', '%E3%83%86%E3%82%B9%E3%83%88')");

        $this->migrate();

        $this->assertSame(['お知らせ'], $this->slugs('categories'));
        $this->assertSame(['テスト'], $this->slugs('tags'));
    }

    public function testAsciiSlugsAreLeftExactlyAsTheyAre(): void
    {
        $this->pdo->exec("INSERT INTO categories (id, slug) VALUES ('1', 'news'), ('2', 'hello-world')");

        $this->migrate();

        $this->assertSame(['news', 'hello-world'], $this->slugs('categories'));
    }

    public function testARowThatWouldCollideIsLeftRatherThanFailingTheUpgrade(): void
    {
        // (slug, locale) is unique in the real schema, so rewriting row 2 here
        // would abort the migration and block the whole upgrade.
        $this->pdo->exec(
            "INSERT INTO categories (id, slug) VALUES ('1', 'お知らせ'), ('2', '%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B')"
        );

        $this->migrate();

        $this->assertSame(['お知らせ', '%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B'], $this->slugs('categories'));
    }

    public function testCollisionChecksAreScopedToLocale(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO categories (id, slug, locale) VALUES (?, ?, ?)');
        $stmt->execute(['1', '%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B', 'en']);
        $stmt->execute(['2', 'お知らせ', 'ja']);

        $this->migrate();

        $rows = $this->pdo->query(
            'SELECT slug, locale FROM categories ORDER BY locale'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([
            ['slug' => 'お知らせ', 'locale' => 'en'],
            ['slug' => 'お知らせ', 'locale' => 'ja'],
        ], $rows);
    }

    public function testUnsafeDecodedValuesAreLeftEncoded(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO categories (id, slug) VALUES (?, ?)');
        $stmt->execute(['1', 'bad%00slug']);
        $stmt->execute(['2', 'nested%2Fterm']);
        $stmt->execute(['3', '%FF']);

        $this->migrate();

        $this->assertSame(
            ['bad%00slug', 'nested%2Fterm', '%FF'],
            $this->slugs('categories'),
        );
    }

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        $this->pdo->exec("INSERT INTO categories (id, slug) VALUES ('1', '%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B')");

        $this->migrate();
        $this->migrate();

        $this->assertSame(['お知らせ'], $this->slugs('categories'));
    }

    public function testMissingTablesAreSkipped(): void
    {
        $this->pdo->exec('DROP TABLE tags');

        $this->migrate();

        $this->addToAssertionCount(1);
    }
}
