<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Content\PostService;

final class PostServiceExcerptTest extends TestCase
{
    public function testExplicitExcerptWins(): void
    {
        $this->assertSame(
            'Manual excerpt',
            PostService::excerptFromRow([
                'excerpt' => ' Manual excerpt ',
                'body' => $this->doc('Body text'),
            ]),
        );
    }

    public function testFallsBackToPlainTextBody(): void
    {
        $this->assertSame(
            '本文から preview を作ります',
            PostService::excerptFromRow([
                'excerpt' => '',
                'body' => $this->doc('本文から preview を作ります'),
            ]),
        );
    }

    public function testFallbackExcerptUsesMultibyteLength(): void
    {
        $this->assertSame(
            'あいう…',
            PostService::excerptFromRow([
                'excerpt' => '',
                'body' => $this->doc('あいうえお'),
            ], 3),
        );
    }

    public function testImageOnlyBodyReturnsEmptyExcerpt(): void
    {
        $this->assertSame('', PostService::excerptFromRow([
            'excerpt' => '',
            'body' => json_encode([
                'type' => 'doc',
                'content' => [[
                    'type' => 'image',
                    'attrs' => ['src' => '/uploads/photo.jpg'],
                ]],
            ]),
        ]));
    }

    private function doc(string $text): string
    {
        return json_encode([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
