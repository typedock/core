<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;
use TypeDock\Content\TiptapRenderer;

/**
 * Build a flat list of heading entries from the current page's Tiptap body so
 * the TOC template can render anchor links. Anchors share the same slug logic
 * as TiptapRenderer::slugifyHeading so the targets actually exist in the
 * rendered HTML.
 *
 * Returned shape:
 *   [
 *     'items' => [
 *       ['level' => 2, 'text' => '...', 'id' => 'section-slug'],
 *       ...
 *     ],
 *   ]
 */
class TocProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $min = max(2, (int) ($params['min_level'] ?? 2));
        $max = min(4, (int) ($params['max_level'] ?? 3));
        if ($max < $min) {
            $max = $min;
        }

        $body = $context->page['body'] ?? null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            $body = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($body) || ($body['type'] ?? '') !== 'doc') {
            return ['items' => []];
        }

        $items = [];
        $this->collect($body['content'] ?? [], $min, $max, $items);
        return ['items' => $items];
    }

    /**
     * @param array<int, mixed>                                    $nodes
     * @param array<int, array{level:int,text:string,id:string}>  $items
     */
    private function collect(array $nodes, int $min, int $max, array &$items): void
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'heading') {
                $level = (int) ($node['attrs']['level'] ?? 2);
                if ($level >= $min && $level <= $max) {
                    $text = self::headingText($node['content'] ?? []);
                    if ($text !== '') {
                        $items[] = [
                            'level' => $level,
                            'text'  => $text,
                            'id'    => TiptapRenderer::slugifyHeading($text),
                        ];
                    }
                }
                continue;
            }
            if (!empty($node['content']) && is_array($node['content'])) {
                $this->collect($node['content'], $min, $max, $items);
            }
        }
    }

    /**
     * @param array<int, mixed> $inline
     */
    private static function headingText(array $inline): string
    {
        $out = '';
        foreach ($inline as $n) {
            if (!is_array($n)) {
                continue;
            }
            if (($n['type'] ?? '') === 'text') {
                $out .= (string) ($n['text'] ?? '');
            } elseif (!empty($n['content']) && is_array($n['content'])) {
                $out .= self::headingText($n['content']);
            }
        }
        return trim($out);
    }
}
