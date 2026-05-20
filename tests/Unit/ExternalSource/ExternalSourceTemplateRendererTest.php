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
            'slug' => 'intro',
            'url' => '/docs/intro',
            'content' => "**Hello**\n\n<script>alert(1)</script>",
        ];

        $html = (new ExternalSourceTemplateRenderer())->render('[resource.content|markdown]', $resource);

        $this->assertStringContainsString('<strong>Hello</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testMarkdownFormatterSupportsGfmTables(): void
    {
        $resource = (object) [
            'slug' => 'tables',
            'url' => '/docs/tables',
            'content' => "| Name | Value |\n| --- | --- |\n| Core | Typed |",
        ];

        $html = (new ExternalSourceTemplateRenderer())->render('[resource.content|markdown]', $resource);

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringContainsString('<th>Name</th>', $html);
        $this->assertStringContainsString('<td>Typed</td>', $html);
    }

    public function testMarkdownFormatterRewritesRelativeMarkdownLinks(): void
    {
        $resource = (object) [
            'slug' => 'guides/latte-quickref',
            'url' => '/docs/guides/latte-quickref',
            'content' => '[Template reference](../theme-template-reference.md#variables)',
        ];

        $html = (new ExternalSourceTemplateRenderer())->render('[resource.content|markdown]', $resource);

        $this->assertStringContainsString('href="/docs/theme-template-reference#variables"', $html);
        $this->assertStringNotContainsString('.md', $html);
    }

    public function testMarkdownFormatterKeepsBlankLinesInsideCodeFences(): void
    {
        $resource = (object) [
            'slug' => 'latte-quickref',
            'url' => '/docs/latte-quickref',
            'content' => <<<'MD'
```latte
{literal}

function greet(name) { return `Hello ${name}`; }

{/literal}
```
MD,
        ];

        $html = (new ExternalSourceTemplateRenderer())->render('[resource.content|markdown]', $resource);

        $this->assertStringContainsString('<pre><code class="language-latte">', $html);
        $this->assertStringContainsString('{literal}', $html);
        $this->assertStringNotContainsString('<p>', $html);
        $this->assertStringNotContainsString('<br>', $html);
    }
}
