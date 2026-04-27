<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Component\ComponentRenderer;
use TypeDock\Component\ComponentRegistry;
use TypeDock\Component\RenderContext;
use TypeDock\Content\TiptapRenderer;
use TypeDock\Theme\LatteFactory;

/**
 * Unit tests for the ProseMirror-JSON → HTML renderer used at frontend.
 * The renderer is the trust boundary: editor input becomes HTML here, so
 * we pin the shape of the output (semantic tags, escaping, sanitised link
 * marks) explicitly.
 */
final class TiptapRendererTest extends TestCase
{
    private TiptapRenderer $renderer;

    protected function setUp(): void
    {
        $componentRenderer = new class(new ComponentRegistry(), $this->fakeLatte()) extends ComponentRenderer {
            public function render(string $type, array $params = [], ?RenderContext $context = null): string
            {
                return '<div class="stub-component" data-type="' . htmlspecialchars($type) . '"></div>';
            }
        };
        $this->renderer = new TiptapRenderer($componentRenderer);
    }

    private function fakeLatte(): LatteFactory
    {
        // ComponentRenderer's parent constructor requires a LatteFactory; we
        // never reach the path that uses it in this stub, but PHP still
        // instantiates the parent.
        return new LatteFactory(TYPEDOCK_ROOT . '/themes', 'default');
    }

    public function testEmptyInputsRenderEmpty(): void
    {
        $this->assertSame('', $this->renderer->render(null));
        $this->assertSame('', $this->renderer->render(''));
        $this->assertSame('', $this->renderer->render('not json'));
        $this->assertSame('', $this->renderer->render(['type' => 'paragraph']));
    }

    public function testParagraphAndTextMarks(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello ', 'marks' => [['type' => 'bold']]],
                    ['type' => 'text', 'text' => 'world'],
                    ['type' => 'text', 'text' => '<script>'],
                ],
            ]],
        ];
        $html = $this->renderer->render($doc);
        $this->assertSame('<p><strong>Hello </strong>world&lt;script&gt;</p>', $html);
    }

    public function testHeadingGeneratesSlugId(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 2],
                'content' => [['type' => 'text', 'text' => 'Hello World']],
            ]],
        ];
        $html = $this->renderer->render($doc);
        $this->assertSame('<h2 id="hello-world">Hello World</h2>', $html);
    }

    public function testHeadingLevelClampedToSafeRange(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 7],
                'content' => [['type' => 'text', 'text' => 'X']],
            ]],
        ];
        // Any out-of-range level collapses to h2.
        $this->assertSame('<h2 id="x">X</h2>', $this->renderer->render($doc));
    }

    public function testListsAndBlockquote(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'bulletList',
                    'content' => [
                        [
                            'type' => 'listItem',
                            'content' => [[
                                'type' => 'paragraph',
                                'content' => [['type' => 'text', 'text' => 'a']],
                            ]],
                        ],
                    ],
                ],
                [
                    'type' => 'blockquote',
                    'content' => [[
                        'type' => 'paragraph',
                        'content' => [['type' => 'text', 'text' => 'quoted']],
                    ]],
                ],
            ],
        ];
        $html = $this->renderer->render($doc);
        $this->assertSame(
            '<ul><li><p>a</p></li></ul><blockquote><p>quoted</p></blockquote>',
            $html,
        );
    }

    public function testCodeBlockEscapes(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'codeBlock',
                'attrs' => ['language' => 'php'],
                'content' => [['type' => 'text', 'text' => "<?php echo 'hi';"]],
            ]],
        ];
        $html = $this->renderer->render($doc);
        $this->assertSame(
            '<pre><code class="language-php">&lt;?php echo &#039;hi&#039;;</code></pre>',
            $html,
        );
    }

    public function testHorizontalRuleAndHardBreak(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'horizontalRule'],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => 'a'],
                        ['type' => 'hardBreak'],
                        ['type' => 'text', 'text' => 'b'],
                    ],
                ],
            ],
        ];
        $html = $this->renderer->render($doc);
        $this->assertSame('<hr><p>a<br>b</p>', $html);
    }

    public function testLinkMarkRejectsJavascriptUri(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'click',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]],
                ]],
            ]],
        ];
        // javascript: hrefs are dropped — the text is rendered without the <a>.
        $this->assertSame('<p>click</p>', $this->renderer->render($doc));
    }

    public function testLinkMarkEscapesHref(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'hi',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://a.test/?x="&y']]],
                ]],
            ]],
        ];
        $this->assertSame(
            '<p><a href="https://a.test/?x=&quot;&amp;y">hi</a></p>',
            $this->renderer->render($doc),
        );
    }

    public function testHighlightMarkClampsUnknownColor(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => 'x',
                    'marks' => [['type' => 'highlight', 'attrs' => ['color' => 'hotpink']]],
                ]],
            ]],
        ];
        $this->assertSame(
            '<p><mark class="highlight highlight--yellow">x</mark></p>',
            $this->renderer->render($doc),
        );
    }

    public function testImageNodeEscapesAndClampsAlign(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => [
                    'src'   => '/uploads/a.jpg',
                    'alt'   => 'alt"><x',
                    'align' => 'weird',
                    'width' => 320,
                    'caption' => 'Nice shot',
                ],
            ]],
        ];
        $html = $this->renderer->render($doc);
        $this->assertStringContainsString('align-center', $html); // unknown align → center
        $this->assertStringContainsString('src="/uploads/a.jpg"', $html);
        $this->assertStringContainsString('alt="alt&quot;&gt;&lt;x"', $html);
        $this->assertStringContainsString('style="width:320px"', $html);
        $this->assertStringContainsString('<figcaption>Nice shot</figcaption>', $html);
    }

    public function testComponentBlockDelegatesToRenderer(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'componentBlock',
                'attrs' => ['component' => 'search_form', 'params' => []],
            ]],
        ];
        $html = $this->renderer->render($doc);
        $this->assertSame(
            '<div class="td-component-block td-component-block--search_form">'
            . '<div class="stub-component" data-type="search_form"></div>'
            . '</div>',
            $html
        );
    }

    public function testJsonStringIsParsed(): void
    {
        $json = json_encode([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'hi']],
            ]],
        ]);
        $this->assertSame('<p>hi</p>', $this->renderer->render($json));
    }

    public function testPlainTextExtractsNestedTextAndHardBreaks(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'content' => [['type' => 'text', 'text' => 'Hello']],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        ['type' => 'text', 'text' => '世界'],
                        ['type' => 'hardBreak'],
                        ['type' => 'text', 'text' => 'again'],
                    ],
                ],
                [
                    'type' => 'bulletList',
                    'content' => [[
                        'type' => 'listItem',
                        'content' => [[
                            'type' => 'paragraph',
                            'content' => [['type' => 'text', 'text' => 'nested']],
                        ]],
                    ]],
                ],
            ],
        ];

        $this->assertSame('Hello 世界 again nested', TiptapRenderer::toPlainText($doc));
    }

    public function testPlainTextHandlesJsonStringAndInvalidInput(): void
    {
        $json = json_encode([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'From JSON']],
            ]],
        ]);

        $this->assertSame('From JSON', TiptapRenderer::toPlainText($json));
        $this->assertSame('', TiptapRenderer::toPlainText('not json'));
        $this->assertSame('', TiptapRenderer::toPlainText(null));
    }

    public function testPlainTextReturnsEmptyForImageOnlyDocument(): void
    {
        $doc = [
            'type' => 'doc',
            'content' => [[
                'type' => 'image',
                'attrs' => ['src' => '/uploads/photo.jpg'],
            ]],
        ];

        $this->assertSame('', TiptapRenderer::toPlainText($doc));
    }
}
