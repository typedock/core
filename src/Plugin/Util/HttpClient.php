<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Util;

/**
 * Thin curl wrapper so plugin authors don't have to `composer require` Guzzle
 * for a handful of API calls. Intentionally minimal: no middleware, no retry,
 * no streaming. If a plugin needs those, it can still pull Guzzle in on its
 * own — this is a shortcut for the 90% case.
 *
 * Safe defaults:
 *   - 10 second total timeout
 *   - 5 MB response cap
 *   - HTTPS redirect follow disabled (caller decides)
 *   - User-Agent identifies TypeDock
 */
class HttpClient
{
    private const DEFAULT_TIMEOUT     = 10;
    private const DEFAULT_MAX_SIZE    = 5 * 1024 * 1024;
    private const DEFAULT_USER_AGENT  = 'TypeDock/1.0 PluginHttpClient';

    public function __construct(private readonly string $pluginSlug = '') {}

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $options
     */
    public function get(string $url, array $headers = [], array $options = []): HttpResponse
    {
        return $this->request('GET', $url, null, $headers, $options);
    }

    /**
     * @param string|array<string, mixed>|null $body   JSON-encoded if array, raw string otherwise
     * @param array<string, string>            $headers
     * @param array<string, mixed>             $options
     */
    public function post(string $url, string|array|null $body = null, array $headers = [], array $options = []): HttpResponse
    {
        return $this->request('POST', $url, $body, $headers, $options);
    }

    public function put(string $url, string|array|null $body = null, array $headers = [], array $options = []): HttpResponse
    {
        return $this->request('PUT', $url, $body, $headers, $options);
    }

    public function delete(string $url, array $headers = [], array $options = []): HttpResponse
    {
        return $this->request('DELETE', $url, null, $headers, $options);
    }

    /**
     * @param string|array<string, mixed>|null $body
     * @param array<string, string>            $headers
     * @param array<string, mixed>             $options  timeout, max_size, follow_redirects, user_agent
     */
    public function request(
        string $method,
        string $url,
        string|array|null $body = null,
        array $headers = [],
        array $options = []
    ): HttpResponse {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('HttpClient requires the curl extension.');
        }

        // Arrays become JSON bodies automatically with the matching header.
        $contentType = null;
        if (is_array($body)) {
            $body         = (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $contentType  = 'application/json';
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('HttpClient: curl_init failed.');
        }

        $maxSize   = (int) ($options['max_size']          ?? self::DEFAULT_MAX_SIZE);
        $timeout   = (int) ($options['timeout']           ?? self::DEFAULT_TIMEOUT);
        $followRed = (bool)($options['follow_redirects']  ?? false);
        $userAgent = (string)($options['user_agent']      ?? self::DEFAULT_USER_AGENT);

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
            CURLOPT_FOLLOWLOCATION => $followRed,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $outHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$outHeaders): int {
            $len   = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $name  = strtolower(trim($parts[0]));
                $value = trim($parts[1]);
                $outHeaders[$name][] = $value;
            }
            return $len;
        });

        // Abort download once we exceed max_size — prevents a malicious/broken
        // endpoint from filling memory.
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, (function () use ($maxSize) {
            $total = 0;
            $buf   = '';
            return function ($ch, string $chunk) use (&$total, &$buf, $maxSize): int {
                $total += strlen($chunk);
                if ($total > $maxSize) {
                    return 0; // causes curl to abort
                }
                $buf .= $chunk;
                curl_setopt($ch, CURLOPT_PRIVATE, $buf); // stash for retrieval
                return strlen($chunk);
            };
        })());

        $hdrLines = [];
        if ($contentType !== null && !$this->hasHeader($headers, 'Content-Type')) {
            $hdrLines[] = 'Content-Type: ' . $contentType;
        }
        foreach ($headers as $k => $v) {
            $hdrLines[] = $k . ': ' . $v;
        }
        if ($hdrLines !== []) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrLines);
        }

        curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HttpClient: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $body   = (string) curl_getinfo($ch, CURLINFO_PRIVATE);
        curl_close($ch);

        return new HttpResponse($status, $outHeaders, $body);
    }

    /** @param array<string, string> $headers */
    private function hasHeader(array $headers, string $name): bool
    {
        $lower = strtolower($name);
        foreach (array_keys($headers) as $k) {
            if (strtolower((string) $k) === $lower) {
                return true;
            }
        }
        return false;
    }
}
