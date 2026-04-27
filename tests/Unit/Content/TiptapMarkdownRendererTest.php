<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Content\TiptapMarkdownRenderer;

final class TiptapMarkdownRendererTest extends TestCase
{
    public function testRendersCommonBlocksAndMarks(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 2],
                    'content' => [['type' => 'text', 'text' => 'Hello World']],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'A '],
                        ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]],
                        ['type' => 'text', 'text' => ' '],
                        ['type' => 'text', 'text' => 'link', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.com/a b']]]],
                    ],
                ],
                [
                    'type' => 'bulletList',
                    'content' => [[
                        'type' => 'listItem',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => 'One']],
                        ]],
                    ]],
                ],
            ],
        ];

        $this->assertSame(
            "## Hello World\n\nA **bold** [link](https://example.com/a%20b)\n\n- One",
            TiptapMarkdownRenderer::render($doc)
        );
    }

    public function testRendersMediaAndCustomBlocks(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'image',
                    'attrs' => [
                        'src' => '/uploads/photo.jpg',
                        'alt' => 'Photo [alt]',
                        'caption' => 'Nice *shot*',
                    ],
                ],
                [
                    'type' => 'bookmark',
                    'attrs' => [
                        'url' => 'https://example.com',
                        'title' => 'Example',
                        'description' => 'A useful link',
                    ],
                ],
                [
                    'type' => 'componentBlock',
                    'attrs' => [
                        'component' => 'form',
                        'params' => ['slug' => 'contact'],
                    ],
                ],
            ],
        ];

        $this->assertSame(
            "![Photo \\[alt\\]](/uploads/photo.jpg)\n\n_Nice \\*shot\\*_\n\n"
            . "[Example](https://example.com)\n\n> A useful link\n\n"
            . "::component{name=\"form\" params=\"{\\\"slug\\\":\\\"contact\\\"}\"}\n::",
            TiptapMarkdownRenderer::render($doc)
        );
    }

    public function testRejectsInvalidInput(): void
    {
        $this->assertSame('', TiptapMarkdownRenderer::render(null));
        $this->assertSame('', TiptapMarkdownRenderer::render('not json'));
        $this->assertSame('', TiptapMarkdownRenderer::render(['type' => 'paragraph']));
    }
}
