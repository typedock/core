<?php
declare(strict_types=1);

namespace TypeDock\Content;

use TypeDock\Component\ComponentRenderer;
use TypeDock\Component\RenderContext;

/**
 * Render a Tiptap / ProseMirror JSON document to HTML for frontend display.
 *
 * This is the successor to the block-array + Markdown renderer. The editor
 * saves JSON of shape `{ type: 'doc', content: [...] }`; this class walks the
 * tree and emits safe HTML. Component blocks delegate to ComponentRenderer so
 * server-rendered widgets (related posts, search form, custom components)
 * plug in without the editor knowing their markup.
 */
class TiptapRenderer
{
    public function __construct(
        private readonly ComponentRenderer $componentRenderer,
        private readonly ?RenderContext $context = null,
    ) {}

    /**
     * @param  string|array<string, mixed>|null $doc  JSON string or decoded array
     */
    public function render(string|array|null $doc): string
    {
        if ($doc === null || $doc === '') {
            return '';
        }
        if (is_string($doc)) {
            $decoded = json_decode($doc, true);
            if (!is_array($decoded)) {
                return '';
            }
            $doc = $decoded;
        }
        if (($doc['type'] ?? '') !== 'doc') {
            return '';
        }
        return $this->renderNodes($doc['content'] ?? []);
    }

    /**
     * Extract readable text from a Tiptap document without rendering HTML.
     *
     * @param  string|array<string, mixed>|null $doc
     */
    public static function toPlainText(string|array|null $doc): string
    {
        if ($doc === null || $doc === '') {
            return '';
        }
        if (is_string($doc)) {
            $decoded = json_decode($doc, true);
            if (!is_array($decoded)) {
                return '';
            }
            $doc = $decoded;
        }
        if (($doc['type'] ?? '') !== 'doc') {
            return '';
        }
        return trim(self::plainTextFromNodes($doc['content'] ?? []));
    }

    /**
     * @param array<int, mixed> $nodes
     */
    private static function plainTextFromNodes(array $nodes): string
    {
        $chunks = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if ($type === 'text') {
                $chunks[] = (string) ($node['text'] ?? '');
                continue;
            }
            if ($type === 'hardBreak') {
                $chunks[] = "\n";
                continue;
            }
            $inner = self::plainTextFromNodes($node['content'] ?? []);
            if ($inner !== '') {
                $chunks[] = $inner;
            }
        }
        return trim(preg_replace('/[ \t\r\n]+/u', ' ', implode(' ', $chunks)) ?? '');
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function renderNodes(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $html .= $this->renderNode($node);
        }
        return $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function renderNode(array $node): string
    {
        $type = (string) ($node['type'] ?? '');
        return match ($type) {
            'heading'        => $this->heading($node),
            'paragraph'      => $this->paragraph($node),
            'bulletList'     => '<ul>' . $this->renderNodes($node['content'] ?? []) . '</ul>',
            'orderedList'    => '<ol>' . $this->renderNodes($node['content'] ?? []) . '</ol>',
            'listItem'       => '<li>' . $this->renderNodes($node['content'] ?? []) . '</li>',
            'blockquote'     => '<blockquote>' . $this->renderNodes($node['content'] ?? []) . '</blockquote>',
            'codeBlock'      => $this->codeBlock($node),
            'horizontalRule' => '<hr>',
            'hardBreak'      => '<br>',
            'image'          => $this->image($node),
            'bookmark'       => $this->bookmark($node),
            'embed'          => $this->embed($node),
            'componentBlock' => $this->component($node),
            default          => $this->renderNodes($node['content'] ?? []),
        };
    }

    private function heading(array $node): string
    {
        $level = (int) ($node['attrs']['level'] ?? 2);
        if ($level < 2 || $level > 4) $level = 2;
        $inner = $this->renderInline($node['content'] ?? []);
        if ($inner === '') return '';
        $id = $this->slugify(strip_tags($inner));
        return "<h{$level} id=\"{$id}\">{$inner}</h{$level}>";
    }

    private function paragraph(array $node): string
    {
        $inner = $this->renderInline($node['content'] ?? []);
        if ($inner === '') return '';
        return "<p>{$inner}</p>";
    }

    private function codeBlock(array $node): string
    {
        $lang = (string) ($node['attrs']['language'] ?? '');
        $langAttr = $lang !== ''
            ? ' class="language-' . $this->escapeAttr($lang) . '"'
            : '';
        $code = $this->escape($this->getTextContent($node));
        return "<pre><code{$langAttr}>{$code}</code></pre>";
    }

    private function image(array $node): string
    {
        $a       = $node['attrs'] ?? [];
        $src     = (string) ($a['src'] ?? '');
        if ($src === '') return '';
        $alt     = (string) ($a['alt'] ?? '');
        $align   = (string) ($a['align'] ?? 'center');
        if (!in_array($align, ['left', 'center', 'right'], true)) $align = 'center';
        $width   = isset($a['width']) ? (int) $a['width'] : null;
        $caption = (string) ($a['caption'] ?? '');
        $style   = ($width && $width > 0) ? ' style="width:' . $width . 'px"' : '';

        $html = '<figure class="block block-image align-' . $align . '">';
        $html .= '<img src="' . $this->escapeAttr($src) . '" alt="' . $this->escapeAttr($alt) . '"'
              . $style . ' loading="lazy" decoding="async">';
        if ($caption !== '') {
            $html .= '<figcaption>' . $this->escape($caption) . '</figcaption>';
        }
        $html .= '</figure>';
        return $html;
    }

    private function bookmark(array $node): string
    {
        $a     = $node['attrs'] ?? [];
        $url   = (string) ($a['url'] ?? '');
        if ($url === '') return '';
        $title = (string) ($a['title'] ?? $url);
        $desc  = (string) ($a['description'] ?? '');
        $thumb = (string) ($a['thumbnail'] ?? '');
        $fav   = (string) ($a['favicon'] ?? '');
        $host  = parse_url($url, PHP_URL_HOST) ?: $url;

        $thumbHtml = '';
        if ($thumb !== '') {
            $thumbHtml = '<img src="' . $this->escapeAttr($thumb) . '" class="bookmark-thumb" alt="" loading="lazy">';
        }
        $descHtml = '';
        if ($desc !== '') {
            $descHtml = '<p class="bookmark-desc">' . $this->escape($desc) . '</p>';
        }
        $favHtml = '';
        if ($fav !== '') {
            $favHtml = '<img src="' . $this->escapeAttr($fav) . '" class="bookmark-favicon" alt="">';
        }

        return '<a href="' . $this->escapeAttr($url) . '" class="block block-bookmark bookmark-card" '
            . 'target="_blank" rel="noopener noreferrer">'
            . $thumbHtml
            . '<div class="bookmark-info">'
            . '<strong class="bookmark-title">' . $this->escape($title) . '</strong>'
            . $descHtml
            . '<span class="bookmark-url">' . $favHtml . $this->escape($host) . '</span>'
            . '</div>'
            . '</a>';
    }

    private function embed(array $node): string
    {
        $a    = $node['attrs'] ?? [];
        $url  = (string) ($a['url'] ?? '');
        $html = (string) ($a['html'] ?? '');
        // The oEmbed html is sanitised/allowlisted by OembedController before
        // reaching here (iframe src restricted to known providers). Raw urls
        // without an oembed match degrade to a plain link.
        if ($html !== '') {
            return '<div class="block block-embed"><div class="embed-wrapper">' . $html . '</div></div>';
        }
        if ($url === '') return '';
        return '<div class="block block-embed"><a href="' . $this->escapeAttr($url) . '" '
            . 'target="_blank" rel="noopener noreferrer">' . $this->escape($url) . '</a></div>';
    }

    private function component(array $node): string
    {
        $a      = $node['attrs'] ?? [];
        $type   = (string) ($a['component'] ?? '');
        $params = (array)  ($a['params'] ?? []);
        if ($type === '') return '';
        try {
            $inner = $this->componentRenderer->render($type, $params, $this->context);
        } catch (\Throwable) {
            return '<!-- component "' . $this->escape($type) . '" failed to render -->';
        }
        // Wrap inline component output with a stable class theme CSS can target
        // (vertical rhythm, list-reset, etc.) without having to know each
        // component's internal markup.
        $cssType = preg_replace('/[^a-z0-9_-]/i', '', $type) ?? '';
        return '<div class="td-component-block td-component-block--' . $cssType . '">'
            . $inner
            . '</div>';
    }

    // --- inline ---

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private function renderInline(array $nodes): string
    {
        $html = '';
        foreach ($nodes as $node) {
            if (!is_array($node)) continue;
            $type = $node['type'] ?? '';
            if ($type === 'text') {
                $text = $this->escape((string) ($node['text'] ?? ''));
                foreach (array_reverse($node['marks'] ?? []) as $mark) {
                    if (is_array($mark)) {
                        $text = $this->wrapMark($mark, $text);
                    }
                }
                $html .= $text;
            } elseif ($type === 'hardBreak') {
                $html .= '<br>';
            } else {
                // Some extensions emit inline nodes; fall back to their text.
                $html .= $this->escape($this->getTextContent($node));
            }
        }
        return $html;
    }

    /**
     * @param array<string, mixed> $mark
     */
    private function wrapMark(array $mark, string $text): string
    {
        $attrs = $mark['attrs'] ?? [];
        return match ($mark['type'] ?? '') {
            'bold', 'strong' => "<strong>{$text}</strong>",
            'italic', 'em'   => "<em>{$text}</em>",
            'strike'         => "<del>{$text}</del>",
            'code'           => "<code>{$text}</code>",
            'underline'      => "<u>{$text}</u>",
            'link'           => $this->linkMark($attrs, $text),
            'highlight'      => $this->highlightMark($attrs, $text),
            default          => $text,
        };
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function linkMark(array $attrs, string $text): string
    {
        $href = (string) ($attrs['href'] ?? '');
        if ($href === '') return $text;
        // Block javascript: and data: URIs.
        if (preg_match('/^\s*(javascript|data|vbscript):/i', $href)) {
            return $text;
        }
        $target = (string) ($attrs['target'] ?? '');
        $extra = '';
        if ($target !== '') {
            $extra = ' target="' . $this->escapeAttr($target) . '" rel="noopener noreferrer"';
        }
        return '<a href="' . $this->escapeAttr($href) . '"' . $extra . '>' . $text . '</a>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private function highlightMark(array $attrs, string $text): string
    {
        $color = (string) ($attrs['color'] ?? 'yellow');
        if (!in_array($color, ['yellow', 'red', 'green', 'blue'], true)) {
            $color = 'yellow';
        }
        return '<mark class="highlight highlight--' . $color . '">' . $text . '</mark>';
    }

    // --- utility ---

    /**
     * @param array<string, mixed> $node
     */
    private function getTextContent(array $node): string
    {
        if (isset($node['text'])) return (string) $node['text'];
        $text = '';
        foreach (($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $text .= $this->getTextContent($child);
            }
        }
        return $text;
    }

    private function slugify(string $text): string
    {
        return self::slugifyHeading($text);
    }

    /**
     * Heading slug: lowercase, strip punctuation, keep unicode letters/digits.
     * Public so the TOC component can compute matching anchor IDs without
     * having to re-render the document.
     */
    public static function slugifyHeading(string $text): string
    {
        $slug = mb_strtolower(trim($text));
        $slug = preg_replace('/\s+/u', '-', $slug) ?? '';
        $slug = preg_replace('/[^\p{L}\p{N}\-]/u', '', $slug) ?? '';
        return $slug !== '' ? $slug : 'section';
    }

    private function escape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttr(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
