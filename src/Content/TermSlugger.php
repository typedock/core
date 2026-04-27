<?php
declare(strict_types=1);

namespace TypeDock\Content;

final class TermSlugger
{
    public static function fromName(string $name, string $fallbackPrefix): string
    {
        return self::normalize($name, $fallbackPrefix . '-' . date('YmdHis'));
    }

    public static function normalize(string $value, string $fallback): string
    {
        $slug = mb_strtolower(trim(rawurldecode($value)), 'UTF-8');
        $slug = preg_replace('/[\s\-]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            return $fallback;
        }

        return rawurlencode($slug);
    }
}
