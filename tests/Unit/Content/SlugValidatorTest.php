<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeDock\Content\SlugValidator;
use TypeDock\Exception\ValidationException;

final class SlugValidatorTest extends TestCase
{
    private SlugValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SlugValidator();
    }

    public function testAcceptsSimpleLowercaseSlug(): void
    {
        $this->validator->validate('hello-world');
        $this->addToAssertionCount(1);
    }

    public function testAcceptsNestedSlug(): void
    {
        $this->validator->validate('docs/getting-started');
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidSlugProvider(): array
    {
        return [
            'empty'             => ['', 'enter a slug'],
            'uppercase'         => ['HelloWorld', 'lowercase'],
            'uppercase cyrillic'=> ['Привет', 'lowercase'],
            'spaces'            => ['hello world', 'letters, digits'],
            'percent escape'    => ['%E3%83%86', 'letters, digits'],
            'query character'   => ['a?b', 'letters, digits'],
            'dot'               => ['file.php', 'letters, digits'],
            'consecutive slash' => ['foo//bar', 'consecutive slashes'],
            // Invisible: category Lo, so \p{L} admits them. A slug made of
            // these shows nothing in an admin list, a sitemap or a URL bar.
            'hangul filler'     => ["\u{3164}\u{3164}\u{3164}", 'invisible'],
            'choseong filler'   => ["ab\u{115F}cd", 'invisible'],
            'halfwidth filler'  => ["ab\u{FFA0}cd", 'invisible'],
            // Combining marks: nothing normalises Unicode here, so allowing
            // them would let the decomposed form of a character be a second
            // row answering the same URL.
            'combining stack'   => ['a' . str_repeat("\u{0301}", 60), 'letters, digits'],
            'decomposed kana'   => ["\u{304B}\u{3099}\u{304B}\u{3099}\u{304B}\u{3099}", 'letters, digits'],
            'too long'          => [str_repeat('a', 1001), 'too long'],
            'too short'         => ['ab', 'at least 3'],
            'reserved admin'    => ['admin', 'system-reserved'],
            'reserved api sub'  => ['api/v1', 'system-reserved'],
            'locale code'       => ['en', 'at least 3'],
            'locale region'     => ['pt-br', 'language codes'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unicodeSlugProvider(): array
    {
        return [
            'japanese'          => ['お知らせ'],
            'two-character cjk' => ['会社'],
            'japanese path'     => ['事例/顧客-a'],
            'cyrillic'          => ['новости'],
            'mixed'             => ['blog-2024-お知らせ'],
            // Both are category Lm. Dropping \p{Lm} to shut out U+0640
            // TATWEEL would have taken these with it — ordinary Japanese.
            'prolonged sound'   => ['コーヒー'],
            'iteration mark'    => ['人々'],
            // Precomposed: the form an IME actually emits.
            'composed kana'     => ['がぎぐ'],
        ];
    }

    #[DataProvider('unicodeSlugProvider')]
    public function testAcceptsNonAsciiSlug(string $slug): void
    {
        $this->validator->validate($slug);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidSlugProvider')]
    public function testRejectsInvalidSlug(string $slug, string $expectedMessageFragment): void
    {
        try {
            $this->validator->validate($slug);
            $this->fail("Expected slug \"{$slug}\" to be rejected.");
        } catch (ValidationException $e) {
            $errors = $e->getErrors();
            $this->assertArrayHasKey('slug', $errors);
            $joined = implode(' | ', $errors['slug']);
            $this->assertStringContainsStringIgnoringCase($expectedMessageFragment, $joined);
        }
    }

    public function testGenerateUniqueAddsSuffixWhenCollides(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE posts (id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');
        $pdo->exec("INSERT INTO posts (id, slug) VALUES ('1', 'hello-world')");

        $slug = $this->validator->generateUnique('Hello World', $pdo);
        $this->assertSame('hello-world-2', $slug);
    }

    public function testGenerateUniquePrefixesReservedTopLevel(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE posts (id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');

        // "admin" is reserved → generator must prefix with "p-".
        $slug = $this->validator->generateUnique('admin', $pdo);
        $this->assertSame('p-admin', $slug);
    }

    public function testAdoptUniqueKeepsAHierarchicalPath(): void
    {
        // The importer arrives with a slug the source site already chose.
        // Deriving one from a title would flatten the path away.
        $slug = $this->validator->adoptUnique('showcase/customer-a', $this->postsTable());

        $this->assertSame('showcase/customer-a', $slug);
        $this->validator->validate($slug);
    }

    public function testAdoptUniqueSuffixesTheWholePathOnCollision(): void
    {
        $pdo = $this->postsTable();
        $pdo->exec("INSERT INTO posts (id, slug) VALUES ('1', 'showcase/customer-a')");

        $this->assertSame('showcase/customer-a-2', $this->validator->adoptUnique('showcase/customer-a', $pdo));
    }

    public function testJapaneseTitleProducesAJapaneseSlugRatherThanATimestamp(): void
    {
        $slug = $this->validator->generateUnique('お問い合わせ', $this->postsTable());

        $this->assertSame('お問い合わせ', $slug);
    }

    public function testTitleSeparatorsStillCollapseAcrossScripts(): void
    {
        $this->assertSame(
            '事例-顧客-a',
            $this->validator->generateUnique('事例 — 顧客 A', $this->postsTable())
        );
    }

    public function testShortNonAsciiSlugIsNotPaddedLikeALanguageCode(): void
    {
        // The 3-character floor guards against slugs shaped like `en`/`ja`;
        // 会社 cannot be mistaken for one, so it is left alone.
        $this->assertSame('会社', $this->validator->adoptUnique('会社', $this->postsTable()));
    }

    public function testAdoptUniqueChecksLengthOnTheTopLevelSegment(): void
    {
        // `a/bcd` is long enough overall but validate() judges the first
        // segment, which is what has to clear three characters.
        $this->assertSame('page-a/bcd', $this->validator->adoptUnique('a/bcd', $this->postsTable()));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function lookupCandidateProvider(): array
    {
        return [
            // What an anonymous request can put on the wire. Flight urldecodes
            // route parameters and the page catch-all decodes the path itself,
            // so these arrive as raw bytes at a lookup.
            'ordinary ascii'      => ['hello-world', true],
            'japanese'            => ['お知らせ', true],
            'hierarchical'        => ['showcase/customer-a', true],
            // Not validate()'s job to like it — a lookup just will not match.
            'uppercase'           => ['HELLO', true],
            'reserved word'       => ['admin', true],

            'empty'               => ['', false],
            'invalid utf-8'       => ["\xFF\xFE", false],
            'overlong encoding'   => ["\xC0\xAE\xC0\xAE", false],
            'truncated sequence'  => ["\xE3\x81", false],
            'nul byte'            => ["a\x00b", false],
            'control character'   => ["a\x1Fb", false],
            'over the column'     => [str_repeat('a', 1001), false],
        ];
    }

    #[DataProvider('lookupCandidateProvider')]
    public function testIsResolvableScreensWhatReachesTheDatabase(string $slug, bool $expected): void
    {
        $this->assertSame($expected, SlugValidator::isResolvable($slug));
    }

    public function testInvisibleLettersAreStrippedWhenDerivingFromATitle(): void
    {
        $slug = $this->validator->generateUnique("お知\u{3164}らせ", $this->postsTable());

        $this->assertSame('お知らせ', $slug);
        $this->validator->validate($slug);
    }

    public function testATitleOfNothingButInvisiblesDoesNotProduceAnInvisibleSlug(): void
    {
        $slug = $this->validator->generateUnique("\u{3164}\u{115F}", $this->postsTable());

        $this->assertStringStartsWith('post-', $slug, 'Falls back rather than storing an unseeable slug');
        $this->validator->validate($slug);
    }

    private function postsTable(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE posts (id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');

        return $pdo;
    }
}
