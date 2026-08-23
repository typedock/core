<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Redirect;

/**
 * The one validation path for manually entered and imported redirect rules.
 */
final class RedirectRuleValidator
{
    private const STATUS_CODES = [301, 302, 307, 308];
    private const MAX_VALUE_CHARS = 2000;

    /**
     * @return array{0:string,1:string,2:int}
     */
    public function validate(string $source, string $target, string $status): array
    {
        $source = trim($source);
        $target = trim($target);

        if ($source === '' || $target === '') {
            throw new \InvalidArgumentException('source and target are both required.');
        }

        $this->assertSafeText($source, 'source');
        $this->assertSafeText($target, 'target');

        $source = $this->normaliseSource($source);
        $target = $this->normaliseTarget($target);

        if ($source === $target) {
            throw new \InvalidArgumentException('source and target are the same, which would loop.');
        }

        $status = trim($status);
        if ($status === '') {
            $status = '301';
        }
        if (!ctype_digit($status) || !in_array((int) $status, self::STATUS_CODES, true)) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not one of %s.', $status, implode(', ', self::STATUS_CODES))
            );
        }

        return [$source, $target, (int) $status];
    }

    private function normaliseSource(string $source): string
    {
        if (str_starts_with($source, '~')) {
            if (RegexPattern::compile(substr($source, 1)) === null) {
                throw new \InvalidArgumentException(
                    'the regular expression is invalid or exceeds the safe complexity limit.'
                );
            }

            return $source;
        }

        if (preg_match('#^https?://#i', $source) === 1) {
            $parts = parse_url($source);
            $path  = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
            $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';

            // `/?p=123` style permalinks are identified by their query alone;
            // emitting the bare path would redirect the site's home page.
            $source = $path === '' || $path === '/'
                ? ($query !== '' ? '/?' . $query : '')
                : $path . ($query !== '' ? '?' . $query : '');

            if ($source === '') {
                throw new \InvalidArgumentException('the source URL has no path to match on.');
            }
        }

        $source = '/' . ltrim($source, '/');
        $this->assertLength($source, 'source');

        return $source;
    }

    private function normaliseTarget(string $target): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target) === 1) {
            $parts  = parse_url($target);
            $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
            $host   = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                throw new \InvalidArgumentException(
                    'absolute targets must use http or https and include a host.'
                );
            }

            $this->assertLength($target, 'target');
            return $target;
        }

        $target = '/' . ltrim($target, '/');
        $this->assertLength($target, 'target');

        return $target;
    }

    private function assertSafeText(string $value, string $field): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new \InvalidArgumentException("{$field} is not valid UTF-8.");
        }

        // Control/format codepoints include CR/LF (response splitting), NUL,
        // bidi overrides and other invisible state that has no legitimate
        // place in a redirect rule.
        if (preg_match('/\p{C}/u', $value) === 1) {
            throw new \InvalidArgumentException("{$field} contains a control character.");
        }

        $this->assertLength($value, $field);
    }

    private function assertLength(string $value, string $field): void
    {
        if (mb_strlen($value, 'UTF-8') > self::MAX_VALUE_CHARS) {
            throw new \InvalidArgumentException(
                "{$field} exceeds the 2000-character database limit."
            );
        }
    }
}
