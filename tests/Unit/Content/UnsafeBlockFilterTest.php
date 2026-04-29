<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Component\ComponentDefinition;
use TypeDock\Component\ComponentRegistry;
use TypeDock\Content\UnsafeBlockFilter;

/**
 * The filter is the server-side gate for raw-HTML components. It must
 * remove disallowed component blocks regardless of nesting and report
 * them so the controller can warn the saver. Allowed components and
 * non-componentBlock nodes pass through untouched.
 */
final class UnsafeBlockFilterTest extends TestCase
{
    private ComponentRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ComponentRegistry();
        $this->registry->register(new ComponentDefinition(
            type: 'safe_widget',
            name: 'Safe',
        ));
        $this->registry->register(new ComponentDefinition(
            type: 'custom_html',
            name: 'Custom HTML',
            requiresCapability: 'content:unfiltered_html',
        ));
    }

    public function test_keeps_components_when_capability_granted(): void
    {
        $filter = new UnsafeBlockFilter($this->registry, fn() => true);
        $doc = $this->doc([$this->componentNode('custom_html')]);
        $out = $filter->filter($doc);
        self::assertSame($doc, $out);
        self::assertSame([], $filter->getRemoved());
    }

    public function test_strips_components_when_capability_denied(): void
    {
        $filter = new UnsafeBlockFilter($this->registry, fn() => false);
        $doc = $this->doc([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'keep']]],
            $this->componentNode('custom_html'),
            $this->componentNode('safe_widget'),
        ]);
        $out = $filter->filter($doc);
        self::assertCount(2, $out['content']);
        self::assertSame('paragraph', $out['content'][0]['type']);
        self::assertSame('safe_widget', $out['content'][1]['attrs']['component']);
        self::assertSame(['custom_html'], $filter->getRemoved());
    }

    public function test_strips_nested_components(): void
    {
        $filter = new UnsafeBlockFilter($this->registry, fn() => false);
        $doc = $this->doc([[
            'type' => 'blockquote',
            'content' => [
                $this->componentNode('custom_html'),
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'inside']]],
            ],
        ]]);
        $out = $filter->filter($doc);
        self::assertCount(1, $out['content'][0]['content']);
        self::assertSame('paragraph', $out['content'][0]['content'][0]['type']);
        self::assertSame(['custom_html'], $filter->getRemoved());
    }

    public function test_string_input_returns_string_output(): void
    {
        $filter = new UnsafeBlockFilter($this->registry, fn() => false);
        $json = json_encode($this->doc([$this->componentNode('custom_html')]));
        $out = $filter->filter($json);
        self::assertIsString($out);
        $decoded = json_decode($out, true);
        self::assertSame([], $decoded['content']);
    }

    public function test_passes_through_null_and_empty(): void
    {
        $filter = new UnsafeBlockFilter($this->registry, fn() => false);
        self::assertNull($filter->filter(null));
        self::assertSame('', $filter->filter(''));
    }

    public function test_unknown_component_type_is_kept(): void
    {
        // We can't enforce on unknown types — registry lookup is the source
        // of truth for whether a component requires capability. Unknowns
        // pass through (renderer will skip them anyway).
        $filter = new UnsafeBlockFilter($this->registry, fn() => false);
        $doc = $this->doc([$this->componentNode('does_not_exist')]);
        $out = $filter->filter($doc);
        self::assertCount(1, $out['content']);
        self::assertSame([], $filter->getRemoved());
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @return array<string, mixed>
     */
    private function doc(array $content): array
    {
        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentNode(string $type): array
    {
        return [
            'type' => 'componentBlock',
            'attrs' => ['component' => $type, 'params' => []],
        ];
    }
}
