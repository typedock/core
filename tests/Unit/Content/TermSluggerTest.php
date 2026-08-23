<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Content\TermSlugger;

final class TermSluggerTest extends TestCase
{
    public function testAsciiNamesBecomeLowercaseHyphenSlugs(): void
    {
        $this->assertSame('hello-world', TermSlugger::fromName('Hello World', 'tag'));
    }

    public function testUnicodeNamesAreStoredDecodedWithoutTransliteration(): void
    {
        // Stored decoded because that is the form a request arrives in:
        // Flight urldecodes route parameters, so `/tag/テスト` reaches the
        // controller as `テスト`. Storing `%E3%83%86…` made every non-ASCII
        // term archive a 404. Encoding happens on output, in slug_path().
        $this->assertSame('テスト', TermSlugger::fromName('テスト', 'tag'));
    }

    public function testEncodedInputIsDecodedOnceRatherThanKeptEscaped(): void
    {
        // A WordPress export writes category_nicename percent-encoded.
        $this->assertSame(
            'テスト',
            TermSlugger::normalize('%E3%83%86%E3%82%B9%E3%83%88', 'tag-fallback')
        );
    }

    public function testTheSlugRoundTripsThroughAUrl(): void
    {
        $slug = TermSlugger::fromName('お知らせ', 'category');

        // What a browser sends for /tag/<slug>, and what Flight hands back.
        $this->assertSame($slug, urldecode(ltrim(slug_path($slug), '/')));
    }

    public function testUrlStructuralCharactersCannotSurviveInATermSlug(): void
    {
        // A name is user input; a `/` or `?` in the stored slug would build a
        // URL that routes somewhere else entirely.
        $this->assertSame('a-b-c', TermSlugger::fromName('a/b?c', 'tag'));
    }

    public function testEmptyResultFallsBackRatherThanProducingABlankSlug(): void
    {
        $this->assertSame('tag-fallback', TermSlugger::normalize('!!!', 'tag-fallback'));
    }

    public function testInvisibleLettersAreRemovedWithoutLeavingDoubledSeparators(): void
    {
        // Stripped before the separator pass, or the hyphens that sat either
        // side of the filler would survive as `a--b`.
        $this->assertSame('a-b', TermSlugger::fromName("a \u{3164} b", 'tag'));
        $this->assertSame('tag-fallback', TermSlugger::normalize("\u{3164}\u{FFA0}", 'tag-fallback'));
    }

    public function testModifierLettersSurviveBecauseTheyAreOrdinaryJapanese(): void
    {
        $this->assertSame('コーヒー', TermSlugger::fromName('コーヒー', 'tag'));
        $this->assertSame('人々', TermSlugger::fromName('人々', 'tag'));
    }

    public function testEveryTermSlugIsAcceptableToTheValidator(): void
    {
        // The two must agree: a term slug the validator would reject is a row
        // that cannot be re-saved from the admin form.
        $validator = new \TypeDock\Content\SlugValidator();

        foreach (['お知らせ', 'コーヒー', 'Hello World', 'a/b?c', '2024 news'] as $name) {
            $validator->validate(TermSlugger::fromName($name, 'category'));
        }

        $this->addToAssertionCount(5);
    }
}
