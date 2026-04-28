<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\TestCase;
use TypeDock\ExternalSource\WordPressRestAdapter;

final class WordPressRestAdapterTest extends TestCase
{
    public function testNormalizePostConvertsRenderedHtmlToTextAndEmbeddedFields(): void
    {
        $adapter = new WordPressRestAdapter();
        $method = new \ReflectionMethod($adapter, 'normalizePost');

        $item = $method->invoke($adapter, [
            'id' => 42,
            'slug' => 'hello-world',
            'date_gmt' => '2026-04-28T10:00:00',
            'modified_gmt' => '2026-04-28T11:00:00',
            'type' => 'post',
            'status' => 'publish',
            'link' => 'https://example.test/hello-world/',
            'title' => ['rendered' => 'Hello <em>World</em>'],
            'excerpt' => ['rendered' => '<p>Short <strong>summary</strong>.</p>'],
            'content' => ['rendered' => '<p>First paragraph.</p><p>Second &amp; final.</p>'],
            '_embedded' => [
                'wp:featuredmedia' => [[
                    'source_url' => 'https://example.test/full.jpg',
                    'media_details' => [
                        'sizes' => [
                            'large' => ['source_url' => 'https://example.test/large.jpg'],
                        ],
                    ],
                ]],
                'wp:term' => [
                    [
                        ['taxonomy' => 'category', 'name' => 'News'],
                    ],
                    [
                        ['taxonomy' => 'post_tag', 'name' => 'Release'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('hello-world', $item['fields']['slug']);
        $this->assertSame('Hello World', $item['fields']['title']);
        $this->assertSame("First paragraph.\nSecond & final.", $item['fields']['content']);
        $this->assertSame('https://example.test/large.jpg', $item['fields']['featured_image_url']);
        $this->assertSame(['News'], $item['fields']['categories']);
        $this->assertSame(['Release'], $item['fields']['tags']);
    }

    public function testNormalizePostFallsBackToPostIdSlug(): void
    {
        $adapter = new WordPressRestAdapter();
        $method = new \ReflectionMethod($adapter, 'normalizePost');

        $item = $method->invoke($adapter, [
            'id' => 99,
            'slug' => '',
            'title' => ['rendered' => 'Untitled'],
        ]);

        $this->assertSame('post-99', $item['fields']['slug']);
    }

    public function testNormalizePostDropsEmbeddedScriptContent(): void
    {
        $adapter = new WordPressRestAdapter();
        $method = new \ReflectionMethod($adapter, 'normalizePost');

        $item = $method->invoke($adapter, [
            'id' => 100,
            'title' => ['rendered' => 'Contact'],
            'content' => [
                'rendered' => '<p>Before form.</p>'
                    . '<script>window.hsFormsOnReady = window.hsFormsOnReady || []; hbspt.forms.create({ portalId: 1 });</script>'
                    . '<div id="hbspt-form-123"></div>'
                    . '<p>After form.</p>',
            ],
        ]);

        $this->assertSame("Before form.\nAfter form.", $item['fields']['content']);
        $this->assertStringNotContainsString('hsFormsOnReady', $item['fields']['content']);
        $this->assertStringNotContainsString('hbspt', $item['fields']['content']);
    }
}
