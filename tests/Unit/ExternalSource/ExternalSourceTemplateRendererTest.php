<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\TestCase;
use TypeDock\ExternalSource\ExternalSourceTemplateRenderer;

final class ExternalSourceTemplateRendererTest extends TestCase
{
    public function testRichTextFormatterKeepsBlockHtmlAtTopLevel(): void
    {
        $resource = (object) [
            'excerpt' => 'Short intro',
            'content' => [
                'nodeType' => 'document',
                'content' => [
                    [
                        'nodeType' => 'heading-2',
                        'content' => [
                            ['nodeType' => 'text', 'value' => 'Body', 'marks' => []],
                        ],
                    ],
                    [
                        'nodeType' => 'paragraph',
                        'content' => [
                            ['nodeType' => 'text', 'value' => 'Hello', 'marks' => []],
                        ],
                    ],
                ],
            ],
        ];

        $html = (new ExternalSourceTemplateRenderer())->render(
            "[resource.excerpt]\n\n[resource.content|richText]",
            $resource
        );

        $this->assertSame("<p>Short intro</p>\n<h2>Body</h2><p>Hello</p>", $html);
    }

    public function testMarkdownFormatterStripsRawHtml(): void
    {
        $resource = (object) [
            'content' => "**Hello**\n\n<script>alert(1)</script>",
        ];

        $html = (new ExternalSourceTemplateRenderer())->render('[resource.content|markdown]', $resource);

        $this->assertStringContainsString('<strong>Hello</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }
}
