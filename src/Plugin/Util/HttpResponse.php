<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Util;

/**
 * Minimal HTTP response value object. Keeps the plugin utility surface free
 * of PSR-7 / PSR-18 dependencies — plugin authors can json_decode($res->body)
 * and be done.
 */
final class HttpResponse
{
    /**
     * @param array<string, array<int, string>> $headers Header name => list of values (header names lowercased)
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        $v = $this->headers[strtolower($name)] ?? null;
        return is_array($v) && isset($v[0]) ? $v[0] : null;
    }

    public function json(): mixed
    {
        $decoded = json_decode($this->body, true);
        return $decoded;
    }
}
