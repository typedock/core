<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

use League\CommonMark\GithubFlavoredMarkdownConverter;

final class ExternalSourceTemplateRenderer
{
    public function render(string $template, object $resource): string
    {
        $template = trim($template);
        if ($template === '') {
            $template = '[resource.excerpt]';
        }

        $rendered = '';
        $blockHtml = [];
        $offset = 0;
        $pattern = '/\[resource\.([A-Za-z0-9_.]+)(?:\|([A-Za-z0-9_]+)(?::"([^"]*)")?)?\]/';
        preg_match_all($pattern, $template, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => $whole) {
            $start = (int) $whole[1];
            $literal = substr($template, $offset, $start - $offset);
            $rendered .= $this->escape($literal);

            $path = (string) $matches[1][$i][0];
            $format = isset($matches[2][$i][0]) ? (string) $matches[2][$i][0] : 'text';
            $arg = isset($matches[3][$i][0]) ? (string) $matches[3][$i][0] : null;
            $formatted = $this->format($this->path($resource, $path), $format, $arg, $resource);
            if ($this->isBlockFormatter($format) && $formatted !== '') {
                $token = '%%TYPEDOCK_HTML_BLOCK_' . count($blockHtml) . '%%';
                $blockHtml[$token] = $formatted;
                $rendered .= "\n\n" . $token . "\n\n";
            } else {
                $rendered .= $formatted;
            }
            $offset = $start + strlen((string) $whole[0]);
        }
        $rendered .= $this->escape(substr($template, $offset));

        return strtr($this->paragraphs($rendered), $blockHtml);
    }

    private function path(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            if (is_object($value) && isset($value->{$part})) {
                $value = $value->{$part};
                continue;
            }
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
                continue;
            }
            return null;
        }
        return $value;
    }

    private function format(mixed $value, string $format, ?string $arg, object $resource): string
    {
        return match ($format) {
            'date' => $this->escape($this->formatDate($value, $arg ?: 'Y-m-d')),
            'number' => is_numeric($value) ? number_format((float) $value) : '',
            'url' => $this->url($value),
            'image' => $this->image($value),
            'richText' => (new StructuredRichTextRenderer())->render($value),
            'markdown' => $this->markdown($value, $resource),
            'join' => $this->escape(implode($arg ?? ', ', $this->listValue($value))),
            default => $this->escape($this->stringValue($value)),
        };
    }

    private function formatDate(mixed $value, string $format): string
    {
        $text = $this->stringValue($value);
        if ($text === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($text))->format($format);
        } catch (\Throwable) {
            return $text;
        }
    }

    private function url(mixed $value): string
    {
        $url = $this->stringValue($value);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        $safe = $this->escape($url);
        return '<a href="' . $safe . '" rel="noopener">' . $safe . '</a>';
    }

    private function image(mixed $value): string
    {
        $url = $this->stringValue($value);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return '<img src="' . $this->escape($url) . '" alt="" loading="lazy">';
    }

    private function markdown(mixed $value, object $resource): string
    {
        $markdown = $this->stringValue($value);
        if ($markdown === '') {
            return '';
        }

        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $this->rewriteMarkdownLinks((string) $converter->convert($markdown), $resource);
    }

    private function rewriteMarkdownLinks(string $html, object $resource): string
    {
        return preg_replace_callback(
            '/\shref=(["\'])([^"\']+)\1/i',
            function (array $matches) use ($resource): string {
                $href = html_entity_decode((string) $matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $next = $this->rewriteMarkdownHref($href, $resource);
                return ' href=' . $matches[1] . $this->escape($next) . $matches[1];
            },
            $html
        ) ?? $html;
    }

    private function rewriteMarkdownHref(string $href, object $resource): string
    {
        if ($href === '' || str_starts_with($href, '#') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) || str_starts_with($href, '//')) {
            return $href;
        }

        $fragment = '';
        $hashPos = strpos($href, '#');
        if ($hashPos !== false) {
            $fragment = substr($href, $hashPos);
            $href = substr($href, 0, $hashPos);
        }

        if (!preg_match('/\.(?:md|markdown)$/i', $href)) {
            return $href . $fragment;
        }

        $target = preg_replace('/\.(?:md|markdown)$/i', '', $href) ?? $href;
        if (str_starts_with($target, '/')) {
            return $target . $fragment;
        }

        $basePath = trim((string) ($resource->url ?? ''), '/');
        $sourceSlug = $basePath !== '' ? strtok($basePath, '/') : '';
        $currentSlug = trim((string) ($resource->slug ?? ''), '/');
        $currentDir = trim(dirname($currentSlug), '. /');
        $relative = $currentDir !== '' ? $currentDir . '/' . $target : $target;
        $normalized = $this->normalizeRelativePath($relative);

        return ($sourceSlug !== '' ? '/' . $sourceSlug . '/' : '') . $normalized . $fragment;
    }

    private function normalizeRelativePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    private function paragraphs(string $text): string
    {
        $parts = preg_split("/\n{2,}/", trim($text)) ?: [];
        $html = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($this->startsWithBlockHtml($part)) {
                $html[] = $part;
                continue;
            }
            $html[] = '<p>' . nl2br($part, false) . '</p>';
        }
        return implode("\n", $html);
    }

    private function startsWithBlockHtml(string $html): bool
    {
        return (bool) preg_match('#^\s*(?:%%TYPEDOCK_HTML_BLOCK_\d+%%|<(?:h[1-6]|p|ul|ol|blockquote|figure|hr|pre|table)\b)#i', $html);
    }

    private function isBlockFormatter(string $format): bool
    {
        return in_array($format, ['richText', 'markdown'], true);
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        return '';
    }

    /**
     * @return array<int, string>
     */
    private function listValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn (mixed $v): string => $this->stringValue($v), $value)));
        }
        return array_values(array_filter(array_map('trim', explode(',', $this->stringValue($value)))));
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
