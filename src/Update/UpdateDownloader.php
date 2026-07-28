<?php
declare(strict_types=1);

namespace TypeDock\Update;

use TypeDock\Http\UrlGuard;

final class UpdateDownloader
{
    public static function readSmallHttps(string $url, int $maxBytes): string
    {
        self::assertHttps($url);
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Unable to allocate an update response buffer.');
        }
        try {
            self::downloadWithCurl($url, $stream, $maxBytes);
            rewind($stream);
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public static function download(string $url, string $destination, int $maxBytes, ?int $expectedBytes = null): void
    {
        self::assertHttps($url);
        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create the update download directory.');
        }

        $part = $destination . '.part';
        $out = fopen($part, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Unable to open the update download file.');
        }

        try {
            self::downloadWithCurl($url, $out, $maxBytes);
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($part);
            throw $e;
        }
        fclose($out);

        $size = (int) filesize($part);
        if ($size < 1 || $size > $maxBytes) {
            @unlink($part);
            throw new \RuntimeException('Downloaded update file has an invalid size.');
        }
        if ($expectedBytes !== null && $size !== $expectedBytes) {
            @unlink($part);
            throw new \RuntimeException("Downloaded update size mismatch: expected {$expectedBytes}, received {$size}.");
        }
        if (!rename($part, $destination)) {
            @unlink($part);
            throw new \RuntimeException('Unable to finalize the update download.');
        }
    }

    /**
     * @param resource $out
     */
    private static function downloadWithCurl(string $url, $out, int $maxBytes): void
    {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('PHP ext-curl is required for DNS-pinned update downloads.');
        }

        $received = 0;
        for ($redirects = 0; $redirects <= 3; $redirects++) {
            $target = UrlGuard::inspect($url);
            $status = 0;
            $location = '';
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Unable to initialize cURL.');
            }
            $pinnedIp = str_contains($target['ip'], ':') ? '[' . $target['ip'] . ']' : $target['ip'];
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => 'TypeDock-Updater/' . (defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : 'unknown'),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$pinnedIp}"],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$status, &$location): int {
                    if (preg_match('#^HTTP/\S+\s+(\d{3})#i', trim($line), $match) === 1) {
                        $status = (int) $match[1];
                        $location = '';
                    } elseif (stripos($line, 'Location:') === 0) {
                        $location = trim(substr($line, strlen('Location:')));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($out, $maxBytes, &$received, &$status): int {
                    $length = strlen($chunk);
                    if ($status < 200 || $status >= 300) {
                        return $length;
                    }
                    $received += $length;
                    if ($received > $maxBytes) {
                        return 0;
                    }
                    $written = fwrite($out, $chunk);
                    return $written === false ? 0 : $written;
                },
            ]);
            $ok = curl_exec($ch);
            $error = curl_error($ch);
            $curlStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $status = $curlStatus !== 0 ? $curlStatus : $status;

            if ($ok !== true) {
                throw new \RuntimeException('Update download failed' . ($error !== '' ? ': ' . $error : '') . '.');
            }
            if ($status >= 200 && $status < 300) {
                return;
            }
            if ($status < 300 || $status >= 400 || $location === '') {
                throw new \RuntimeException("Update download failed (HTTP {$status}).");
            }
            if ($redirects === 3) {
                throw new \RuntimeException('Update download exceeded the redirect limit.');
            }
            $url = self::resolveRedirect($url, $location);
        }
    }

    private static function assertHttps(string $url): void
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException('Update downloads require an HTTPS URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Update URLs must not contain credentials.');
        }
    }

    private static function resolveRedirect(string $baseUrl, string $location): string
    {
        if (str_starts_with($location, 'https://')) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['host'])) {
            throw new \RuntimeException('Update redirect base URL is malformed.');
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }
        $authority = 'https://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
        if (str_starts_with($location, '/')) {
            return $authority . $location;
        }
        $basePath = (string) ($base['path'] ?? '/');
        return $authority . rtrim(str_replace('\\', '/', dirname($basePath)), '/') . '/' . $location;
    }
}
