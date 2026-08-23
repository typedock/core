<?php
declare(strict_types=1);

namespace TypeDock\Content;

final class TermSlugger
{
    public static function fromName(string $name, string $fallbackPrefix): string
    {
        return self::normalize($name, $fallbackPrefix . '-' . date('YmdHis'));
    }

    /**
     * Slugs are stored decoded.
     *
     * This used to return rawurlencode($slug), which no request could ever
     * match: Flight urldecodes route parameters, so `/category/お知らせ`
     * reaches the controller as `お知らせ` while the row held
     * `%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B`. Percent-encoding belongs on the
     * way out — see slug_path() — not in the column.
     *
     * The input is still decoded first, because a WordPress export writes
     * `category_nicename` percent-encoded.
     */
    public static function normalize(string $value, string $fallback): string
    {
        $slug = mb_strtolower(trim(rawurldecode($value)), 'UTF-8');
        // Invisible letters are removed first. They are letters as far as the
        // character class below is concerned, so stripping them afterwards
        // would leave behind the separators that sat either side of them.
        $slug = preg_replace('/[' . SlugValidator::INVISIBLE_LETTERS . ']+/u', '', $slug) ?? '';
        // Everything outside SlugValidator's class becomes a separator, so a
        // term name cannot put a `?`, `#` or `/` into a URL, and cannot
        // smuggle in a combining mark.
        $slug = preg_replace('/[^' . SlugValidator::CHAR_CLASS . ']+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : $fallback;
    }
}
