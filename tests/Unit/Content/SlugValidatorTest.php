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
            'spaces'            => ['hello world', 'lowercase'],
            'consecutive slash' => ['foo//bar', 'consecutive slashes'],
            'too short'         => ['ab', 'at least 3'],
            'reserved admin'    => ['admin', 'system-reserved'],
            'reserved api sub'  => ['api/v1', 'system-reserved'],
            'locale code'       => ['en', 'at least 3'],
            'locale region'     => ['pt-br', 'language codes'],
        ];
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
        $pdo->exec('CREATE TABLE pages (id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');
        $pdo->exec("INSERT INTO pages (id, slug) VALUES ('1', 'hello-world')");

        $slug = $this->validator->generateUnique('Hello World', $pdo);
        $this->assertSame('hello-world-2', $slug);
    }

    public function testGenerateUniquePrefixesReservedTopLevel(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE pages (id TEXT PRIMARY KEY, slug TEXT NOT NULL UNIQUE)');

        // "admin" is reserved → generator must prefix with "p-".
        $slug = $this->validator->generateUnique('admin', $pdo);
        $this->assertSame('p-admin', $slug);
    }
}
