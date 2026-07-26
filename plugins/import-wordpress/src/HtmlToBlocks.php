<?php
declare(strict_types=1);

namespace TypeDock\Plugin\ImportWordPress;

/**
 * Converts a WordPress post body (HTML) into Tiptap nodes.
 *
 * The governing rule comes from watching other migrations fail: **nothing is
 * ever dropped silently**. Anything this converter does not understand is
 * preserved verbatim in a `custom_html` block and counted, so the dry run can
 * say "34 elements will come across as raw HTML" instead of the author
 * discovering months later that every table vanished.
 *
 * Only `<script>` and `<style>` are removed outright: they are the old theme's
 * chrome, never the author's content, and carrying them over would import
 * someone else's CSS into every page.
 */
final class HtmlToBlocks
{
    /** Elements that are containers only — recurse into them, keep the children. */
    private const TRANSPARENT = ['div', 'section', 'article', 'main', 'aside', 'header', 'footer', 'body'];

    private const DROP = ['script', 'style', 'link', 'meta', 'noscript'];

    private int $unmapped = 0;

    /** @var array<int, string> */
    private array $warnings = [];

    /**
     * @return array{blocks: array<int, array<string, mixed>>, unmapped: int, warnings: array<int, string>}
     */
    public function convert(string $html): array
    {
        $this->unmapped = 0;
        $this->warnings = [];

        $html = $this->stripGutenbergComments($html);

        // Classic-editor bodies have no <p> at all; Gutenberg ones already do.
        if (preg_match('/<p[\s>]/i', $html) !== 1) {
            $html = Wpautop::apply($html);
        }

        $root = $this->parse($html);
        if ($root === null) {
            return ['blocks' => [], 'unmapped' => 0, 'warnings' => $this->warnings];
        }

        $blocks = $this->blocksFrom($root);
        if ($blocks === [] && trim(strip_tags($html)) !== '') {
            // Something was there but nothing survived conversion. Never let
            // that happen quietly.
            $blocks = [$this->rawHtml($html)];
        }

        return ['blocks' => $blocks, 'unmapped' => $this->unmapped, 'warnings' => $this->warnings];
    }

    /**
     * Wrap the whole body in a single raw-HTML block. Used for the "import
     * everything as HTML" escape hatch and as a per-post fallback.
     *
     * @return array<int, array<string, mixed>>
     */
    public function convertAsRawHtml(string $html): array
    {
        return [$this->rawHtml($this->stripGutenbergComments($html))];
    }

    /**
     * Gutenberg stores block metadata in HTML comments around ordinary
     * markup. v1 keeps the markup and throws the metadata away — faithful
     * per-block-type conversion buys very little over just reading the HTML
     * that Gutenberg itself renders.
     */
    private function stripGutenbergComments(string $html): string
    {
        return preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $html) ?? $html;
    }

    private function parse(string $html): ?\DOMElement
    {
        $dom = new \DOMDocument();

        $previous = libxml_use_internal_errors(true);
        // Broken markup from long-dead plugins is the norm, not the
        // exception; collect the errors and carry on (doc36 §8-1).
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div id="typedock-import-root">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $found = (new \DOMXPath($dom))->query('//div[@id="typedock-import-root"]');

        return $found !== false && $found->length > 0 && $found->item(0) instanceof \DOMElement
            ? $found->item(0)
            : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blocksFrom(\DOMNode $parent): array
    {
        $blocks = [];

        foreach (iterator_to_array($parent->childNodes) as $child) {
            foreach ($this->blocksFromNode($child) as $block) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blocksFromNode(\DOMNode $node): array
    {
        if ($node instanceof \DOMText) {
            $text = trim($node->textContent);

            return $text === '' ? [] : [$this->paragraphFromInline([$this->text($text)])];
        }

        if (!$node instanceof \DOMElement) {
            return [];
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::DROP, true)) {
            return [];
        }

        if (in_array($tag, self::TRANSPARENT, true)) {
            return $this->hasBlockChild($node)
                ? $this->blocksFrom($node)
                : $this->paragraphBlocks($node);
        }

        return match (true) {
            $tag === 'p'                            => $this->paragraphBlocks($node),
            preg_match('/^h[1-6]$/', $tag) === 1    => [$this->heading($node, (int) substr($tag, 1))],
            $tag === 'ul'                           => [$this->list($node, 'bulletList')],
            $tag === 'ol'                           => [$this->list($node, 'orderedList')],
            $tag === 'blockquote'                   => [['type' => 'blockquote', 'content' => $this->blocksFrom($node)]],
            $tag === 'pre'                          => [$this->codeBlock($node)],
            $tag === 'hr'                           => [['type' => 'horizontalRule']],
            $tag === 'figure'                       => $this->figure($node),
            $tag === 'img'                          => [$this->image($node)],
            $tag === 'br'                           => [],
            default                                 => [$this->unmappedBlock($node)],
        };
    }

    /**
     * A paragraph that contains images has to be split: `image` is a block
     * node in Tiptap, and WordPress wraps standalone images in `<p>` all the
     * time. The text keeps its place, the images follow it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paragraphBlocks(\DOMElement $node): array
    {
        $images = [];
        foreach ($node->getElementsByTagName('img') as $img) {
            $images[] = $img;
        }

        $imageBlocks = [];
        foreach ($images as $img) {
            $imageBlocks[] = $this->image($img);
            $img->parentNode?->removeChild($img);
        }

        $blocks = [];
        $inline = $this->inlineFrom($node, []);
        if ($this->hasVisibleContent($inline)) {
            $blocks[] = $this->paragraphFromInline($inline);
        }

        return array_merge($blocks, $imageBlocks);
    }

    /**
     * @param array<int, array<string, mixed>> $inline
     * @return array<string, mixed>
     */
    private function paragraphFromInline(array $inline): array
    {
        return ['type' => 'paragraph', 'content' => $inline];
    }

    /**
     * @return array<string, mixed>
     */
    private function heading(\DOMElement $node, int $level): array
    {
        // TiptapRenderer only emits h2–h4: h1 belongs to the page title, and
        // h5/h6 collapse rather than disappear.
        $level = max(2, min(4, $level === 1 ? 2 : $level));

        return [
            'type'    => 'heading',
            'attrs'   => ['level' => $level],
            'content' => $this->inlineFrom($node, []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function list(\DOMElement $node, string $type): array
    {
        $items = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || strtolower($child->nodeName) !== 'li') {
                continue;
            }
            $content = $this->hasBlockChild($child)
                ? $this->blocksFrom($child)
                : [$this->paragraphFromInline($this->inlineFrom($child, []))];
            $items[] = ['type' => 'listItem', 'content' => $content];
        }

        return ['type' => $type, 'content' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    private function codeBlock(\DOMElement $node): array
    {
        $language = '';
        $class    = $node->getAttribute('class');
        if (preg_match('/(?:language|lang|brush:)[-\s:]?([a-z0-9#+]+)/i', $class, $m) === 1) {
            $language = strtolower($m[1]);
        }

        return [
            'type'    => 'codeBlock',
            'attrs'   => ['language' => $language],
            'content' => [$this->text($node->textContent)],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function figure(\DOMElement $node): array
    {
        $img = $node->getElementsByTagName('img')->item(0);
        if (!$img instanceof \DOMElement) {
            return [$this->unmappedBlock($node)];
        }

        $caption = '';
        $capNode = $node->getElementsByTagName('figcaption')->item(0);
        if ($capNode !== null) {
            $caption = trim($capNode->textContent);
        }

        return [$this->image($img, $caption)];
    }

    /**
     * Images keep pointing at the source site for now — the media phase is
     * what downloads them into the library and rewrites `src`.
     *
     * @return array<string, mixed>
     */
    private function image(\DOMElement $img, string $caption = ''): array
    {
        $attrs = [
            'src' => trim($img->getAttribute('src')),
            'alt' => trim($img->getAttribute('alt')),
        ];
        if ($caption !== '') {
            $attrs['caption'] = $caption;
        }

        return ['type' => 'image', 'attrs' => $attrs];
    }

    /**
     * @return array<string, mixed>
     */
    private function unmappedBlock(\DOMElement $node): array
    {
        $this->unmapped++;
        $this->warnings[] = sprintf('<%s> kept as raw HTML', strtolower($node->nodeName));

        return $this->rawHtml($this->outerHtml($node));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawHtml(string $html): array
    {
        return [
            'type'  => 'componentBlock',
            'attrs' => [
                'component' => 'custom_html',
                'params'    => ['html' => $html],
            ],
        ];
    }

    // --- inline ------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $marks
     * @return array<int, array<string, mixed>>
     */
    private function inlineFrom(\DOMNode $parent, array $marks): array
    {
        $out = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = $this->collapse($child->textContent);
                if ($text !== '') {
                    $out[] = $this->text($text, $marks);
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);
            if (in_array($tag, self::DROP, true)) {
                continue;
            }
            if ($tag === 'br') {
                $out[] = ['type' => 'hardBreak'];
                continue;
            }

            $mark = $this->markFor($tag, $child);
            $nested = $this->inlineFrom($child, $mark === null ? $marks : array_merge($marks, [$mark]));
            foreach ($nested as $node) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null Null for elements that carry no mark
     *                                   of their own (span, font, …).
     */
    private function markFor(string $tag, \DOMElement $el): ?array
    {
        return match ($tag) {
            'strong', 'b'          => ['type' => 'bold'],
            'em', 'i'              => ['type' => 'italic'],
            'del', 's', 'strike'   => ['type' => 'strike'],
            'u', 'ins'             => ['type' => 'underline'],
            'code', 'kbd', 'samp'  => ['type' => 'code'],
            'mark'                 => ['type' => 'highlight'],
            'a'                    => $this->linkMark($el),
            default                => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function linkMark(\DOMElement $el): ?array
    {
        $href = trim($el->getAttribute('href'));
        if ($href === '' || preg_match('/^\s*(javascript|data|vbscript):/i', $href) === 1) {
            return null;
        }

        return ['type' => 'link', 'attrs' => ['href' => $href]];
    }

    /**
     * @param array<int, array<string, mixed>> $marks
     * @return array<string, mixed>
     */
    private function text(string $text, array $marks = []): array
    {
        $node = ['type' => 'text', 'text' => $text];
        if ($marks !== []) {
            $node['marks'] = array_values($marks);
        }

        return $node;
    }

    /** Collapse runs of whitespace, and treat whitespace-only nodes as empty. */
    private function collapse(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($collapsed) === '' ? '' : $collapsed;
    }

    /**
     * @param array<int, array<string, mixed>> $inline
     */
    private function hasVisibleContent(array $inline): bool
    {
        foreach ($inline as $node) {
            if (($node['type'] ?? '') === 'text' && trim((string) ($node['text'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasBlockChild(\DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement
                && preg_match('/^(p|h[1-6]|ul|ol|blockquote|pre|hr|figure|table|div|section)$/i', $child->nodeName) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function outerHtml(\DOMNode $node): string
    {
        return (string) $node->ownerDocument?->saveHTML($node);
    }
}
