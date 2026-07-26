<?php
declare(strict_types=1);

namespace TypeDock\Security;

/**
 * Builds the admin Content Security Policy and accepts narrowly scoped
 * external-source declarations from enabled plugins.
 *
 * Plugins may add HTTPS origins only to fetch directives. They cannot weaken
 * navigation, embedding, or execution controls such as base-uri,
 * frame-ancestors, form-action, or object-src.
 */
final class AdminCspPolicy
{
    /** @var array<string, list<string>> */
    private array $directives = [
        'default-src'     => ["'self'"],
        'script-src'      => ["'self'", "'unsafe-inline'"],
        'style-src'       => ["'self'", "'unsafe-inline'"],
        'img-src'         => ["'self'", 'data:', 'blob:'],
        'font-src'        => ["'self'", 'data:'],
        'connect-src'     => ["'self'"],
        'frame-src'       => ["'self'"],
        'frame-ancestors' => ["'self'"],
        'base-uri'        => ["'self'"],
        'form-action'     => ["'self'"],
        'object-src'      => ["'none'"],
    ];

    private const EXTENDABLE_DIRECTIVES = [
        'script-src',
        'style-src',
        'img-src',
        'font-src',
        'connect-src',
        'frame-src',
    ];

    /**
     * @param array<string, mixed> $extension
     */
    public function addPluginSources(array $extension): void
    {
        $normalized = self::validatePluginSources($extension);
        foreach ($normalized as $directive => $sources) {
            $this->directives[$directive] = array_values(array_unique([
                ...$this->directives[$directive],
                ...$sources,
            ]));
        }
    }

    /**
     * Validate a plugin manifest declaration without mutating the policy.
     *
     * @param array<string, mixed> $extension
     * @return array<string, list<string>>
     */
    public static function validatePluginSources(array $extension): array
    {
        $normalized = [];
        foreach ($extension as $directive => $sources) {
            if (!is_string($directive) || !in_array($directive, self::EXTENDABLE_DIRECTIVES, true)) {
                throw new \InvalidArgumentException("CSP directive '{$directive}' cannot be extended by plugins.");
            }
            if (!is_array($sources)) {
                throw new \InvalidArgumentException("CSP directive '{$directive}' must contain an array of sources.");
            }

            foreach ($sources as $source) {
                if (!is_string($source) || !self::isAllowedOrigin($source, $directive)) {
                    throw new \InvalidArgumentException(
                        "CSP source for '{$directive}' must be an HTTPS origin"
                        . ($directive === 'connect-src' ? ' or WSS origin' : '')
                        . ' without a path.'
                    );
                }
                $normalized[$directive][] = rtrim($source, '/');
            }

            $normalized[$directive] = array_values(array_unique($normalized[$directive] ?? []));
        }

        return $normalized;
    }

    public function toHeaderValue(): string
    {
        $parts = [];
        foreach ($this->directives as $directive => $sources) {
            $parts[] = $directive . ' ' . implode(' ', $sources);
        }
        return implode('; ', $parts);
    }

    private static function isAllowedOrigin(string $source, string $directive): bool
    {
        if ($source === '' || preg_match('/[\r\n;]/', $source) === 1) {
            return false;
        }

        $parts = parse_url($source);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $allowedSchemes = $directive === 'connect-src' ? ['https', 'wss'] : ['https'];
        if (!in_array(strtolower((string) $parts['scheme']), $allowedSchemes, true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            return false;
        }
        if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, '*.')) {
            $host = substr($host, 2);
            if (!str_contains($host, '.')) {
                return false;
            }
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
