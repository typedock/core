<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testMarkdownFormatterSupportsGfmTaskLists(): void
    {
        $html = $this->renderMarkdown("- [ ] Pending\n- [x] Done");

        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked=""', $html);
    }

    #[DataProvider('unsafeMarkdownProvider')]
    public function testMarkdownFormatterRejectsXssPayloads(string $markdown): void
    {
        $html = $this->renderMarkdown($markdown);
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!doctype html><html><body><div id="root">' . $html . '</div></body></html>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (['script', 'iframe', 'svg'] as $tag) {
            $this->assertSame(0, $document->getElementsByTagName($tag)->length);
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach ($element->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $this->assertFalse(str_starts_with($name, 'on'), "Unsafe event attribute {$name}");
                if (in_array($name, ['href', 'src'], true)) {
                    $this->assertDoesNotMatchRegularExpression(
                        '/^\s*(?:javascript|vbscript|data):/i',
                        html_entity_decode($attribute->value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    );
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeMarkdownProvider(): iterable
    {
        yield 'javascript link' => ['[xss](javascript:alert(1))'];
        yield 'data link' => ['[xss](data:text/html,<script>alert(1)</script>)'];
        yield 'vbscript link' => ['[xss](vbscript:msgbox(1))'];
        yield 'encoded javascript link' => ['[xss](java&#115;cript:alert(1))'];
        yield 'script html' => ['<script>alert(1)</script>'];
        yield 'event handler image' => ['<img src=x onerror=alert(1)>'];
        yield 'iframe html' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'];
        yield 'details html' => ['<details><summary>Title</summary>Body</details>'];
        yield 'svg event handler' => ['<svg onload=alert(1)></svg>'];
        yield 'nested raw html' => ["- item\n  - <img src=x onerror=alert(1)>"];
        yield 'link title escape' => ['[safe](https://example.com "x&quot; onmouseover=&quot;alert(1)")'];
        yield 'image alt escape' => ['![x" onerror="alert(1)](https://example.com/image.png)'];
    }

    public function testMarkdownFormatterHandlesLongEmphasisInputPromptly(): void
    {
        $started = hrtime(true);
        $this->renderMarkdown(str_repeat('*', 20000) . ' text');
        $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertLessThan(2.0, $elapsedSeconds);
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

    private function renderMarkdown(string $markdown): string
    {
        return (new ExternalSourceTemplateRenderer())->render(
            '[resource.content|markdown]',
            (object) [
                'slug' => 'security',
                'url' => '/docs/security',
                'content' => $markdown,
            ],
        );
    }
}
