<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\TestCase;
use TypeDock\ExternalSource\ContentfulRichTextRenderer;

final class ContentfulRichTextRendererTest extends TestCase
{
    public function testRendersBasicRichTextSafely(): void
    {
        $doc = [
            'nodeType' => 'document',
            'content' => [
                [
                    'nodeType' => 'heading-2',
                    'content' => [
                        ['nodeType' => 'text', 'value' => 'Intro', 'marks' => []],
                    ],
                ],
                [
                    'nodeType' => 'paragraph',
                    'content' => [
                        ['nodeType' => 'text', 'value' => 'Hello ', 'marks' => []],
                        ['nodeType' => 'text', 'value' => '<world>', 'marks' => [['type' => 'bold']]],
                    ],
                ],
            ],
        ];

        $html = (new ContentfulRichTextRenderer())->render($doc);

        $this->assertSame('<h2>Intro</h2><p>Hello <strong>&lt;world&gt;</strong></p>', $html);
    }

    public function testRendersEmbeddedResolvedAsset(): void
    {
        $doc = [
            'nodeType' => 'document',
            'content' => [
                [
                    'nodeType' => 'embedded-asset-block',
                    'data' => [
                        'target' => [
                            'url' => 'https://images.ctfassets.net/example.png',
                            'title' => 'Hero',
                        ],
                    ],
                ],
            ],
        ];

        $html = (new ContentfulRichTextRenderer())->render($doc);

        $this->assertSame('<figure><img src="https://images.ctfassets.net/example.png" alt="Hero" loading="lazy"></figure>', $html);
    }
}
