<?php
declare(strict_types=1);

namespace TypeDock\Plugin\ImportWordPress;

/**
 * Paragraph-wrap "classic editor" content.
 *
 * WordPress stores classic post bodies with bare newlines and no `<p>` tags —
 * `wpautop()` adds them at display time. Importing that text as-is turns a
 * whole article into one enormous paragraph, so the same transformation has
 * to happen here. This is a deliberately smaller version of WordPress's
 * function: enough to recover paragraph and line-break structure, without
 * the pre/shortcode special cases that only matter for round-tripping.
 */
final class Wpautop
{
    private const BLOCK_TAGS = 'table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt'
        . '|ul|ol|li|pre|form|blockquote|address|math|style|script|p|h[1-6]|hr|fieldset'
        . '|figure|figcaption|section|article|aside|header|footer|nav|iframe';

    public static function apply(string $html): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);

        // Give every block-level tag its own blank line so the split below
        // never wraps one in a paragraph.
        $html = preg_replace('!(<(?:' . self::BLOCK_TAGS . ')[\s>/])!i', "\n\n$1", $html) ?? $html;
        $html = preg_replace('!(</(?:' . self::BLOCK_TAGS . ')>)!i', "$1\n\n", $html) ?? $html;

        $out = '';
        foreach (preg_split('/\n\s*\n/', $html) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (preg_match('!^</?(?:' . self::BLOCK_TAGS . ')[\s>/]!i', $chunk) === 1) {
                $out .= $chunk . "\n";
                continue;
            }
            $out .= '<p>' . nl2br($chunk) . "</p>\n";
        }

        return $out;
    }
}
