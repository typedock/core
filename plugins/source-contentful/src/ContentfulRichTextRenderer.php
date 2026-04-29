<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SourceContentful;

final class ContentfulRichTextRenderer
{
    public function render(mixed $value): string
    {
        if (!is_array($value)) {
            return $this->escape(is_scalar($value) ? (string) $value : '');
        }

        if (($value['nodeType'] ?? '') === '') {
            return $this->escape($this->stringValue($value));
        }

        return $this->renderNode($value);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderNode(array $node): string
    {
        $type = (string) ($node['nodeType'] ?? '');
        $content = is_array($node['content'] ?? null) ? $node['content'] : [];

        return match ($type) {
            'document' => $this->renderChildren($content),
            'paragraph' => $this->wrap('p', $this->renderChildren($content)),
            'heading-1' => $this->wrap('h1', $this->renderChildren($content)),
            'heading-2' => $this->wrap('h2', $this->renderChildren($content)),
            'heading-3' => $this->wrap('h3', $this->renderChildren($content)),
            'heading-4' => $this->wrap('h4', $this->renderChildren($content)),
            'heading-5' => $this->wrap('h5', $this->renderChildren($content)),
            'heading-6' => $this->wrap('h6', $this->renderChildren($content)),
            'unordered-list' => $this->wrap('ul', $this->renderChildren($content)),
            'ordered-list' => $this->wrap('ol', $this->renderChildren($content)),
            'list-item' => $this->wrap('li', $this->renderChildren($content)),
            'blockquote' => $this->wrap('blockquote', $this->renderChildren($content)),
            'hr' => '<hr>',
            'hyperlink' => $this->renderHyperlink($node),
            'asset-hyperlink' => $this->renderAssetHyperlink($node),
            'entry-hyperlink' => $this->renderEntryHyperlink($node),
            'embedded-asset-block' => $this->renderEmbeddedAsset($node),
            'embedded-entry-block' => $this->renderEmbeddedEntry($node),
            'text' => $this->renderText($node),
            default => $this->renderChildren($content),
        };
    }

    /**
     * @param array<int, mixed> $children
     */
    private function renderChildren(array $children): string
    {
        $html = '';
        foreach ($children as $child) {
            if (is_array($child)) {
                $html .= $this->renderNode($child);
            }
        }
        return $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderText(array $node): string
    {
        $html = $this->escape((string) ($node['value'] ?? ''));
        $marks = is_array($node['marks'] ?? null) ? $node['marks'] : [];

        foreach ($marks as $mark) {
            if (!is_array($mark)) {
                continue;
            }
            $html = match ((string) ($mark['type'] ?? '')) {
                'bold' => '<strong>' . $html . '</strong>',
                'italic' => '<em>' . $html . '</em>',
                'underline' => '<u>' . $html . '</u>',
                'code' => '<code>' . $html . '</code>',
                default => $html,
            };
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderHyperlink(array $node): string
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $uri = (string) ($data['uri'] ?? '');
        $label = $this->renderChildren(is_array($node['content'] ?? null) ? $node['content'] : []);

        if (!preg_match('#^https?://#i', $uri)) {
            return $label;
        }

        return '<a href="' . $this->escape($uri) . '" rel="noopener">' . $label . '</a>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderAssetHyperlink(array $node): string
    {
        $asset = $this->target($node);
        $url = is_array($asset) ? (string) ($asset['url'] ?? '') : '';
        $label = $this->renderChildren(is_array($node['content'] ?? null) ? $node['content'] : []);
        if ($url === '') {
            return $label;
        }
        return '<a href="' . $this->escape($url) . '" rel="noopener">' . $label . '</a>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderEntryHyperlink(array $node): string
    {
        return $this->renderChildren(is_array($node['content'] ?? null) ? $node['content'] : []);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderEmbeddedAsset(array $node): string
    {
        $asset = $this->target($node);
        if (!is_array($asset)) {
            return '';
        }

        $url = (string) ($asset['url'] ?? '');
        if ($url === '') {
            return '';
        }

        $alt = (string) (($asset['description'] ?? '') ?: ($asset['title'] ?? ''));
        return '<figure><img src="' . $this->escape($url) . '" alt="' . $this->escape($alt) . '" loading="lazy"></figure>';
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderEmbeddedEntry(array $node): string
    {
        $entry = $this->target($node);
        if (!is_array($entry)) {
            return '';
        }

        $fields = is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        $title = $this->stringValue($fields['title'] ?? $fields['name'] ?? '');
        return $title !== '' ? '<p>' . $this->escape($title) . '</p>' : '';
    }

    /**
     * @param array<string, mixed> $node
     * @return mixed
     */
    private function target(array $node): mixed
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        return $data['target'] ?? null;
    }

    private function wrap(string $tag, string $html): string
    {
        return $html === '' ? '' : '<' . $tag . '>' . $html . '</' . $tag . '>';
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (is_array($value)) {
            foreach (['title', 'name', 'description', 'url'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return trim((string) $value[$key]);
                }
            }
        }
        return '';
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
