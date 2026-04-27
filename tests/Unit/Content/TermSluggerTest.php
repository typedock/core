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

    public function testUnicodeNamesArePercentEncodedWithoutTransliteration(): void
    {
        $this->assertSame('%E3%83%86%E3%82%B9%E3%83%88', TermSlugger::fromName('テスト', 'tag'));
    }

    public function testAlreadyEncodedSlugsAreNotDoubleEncoded(): void
    {
        $this->assertSame('%E3%83%86%E3%82%B9%E3%83%88', TermSlugger::normalize('%E3%83%86%E3%82%B9%E3%83%88', 'tag-fallback'));
    }
}
