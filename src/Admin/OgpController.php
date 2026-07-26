<?php
declare(strict_types=1);

namespace TypeDock\Admin;

/**
 * Fetch Open Graph metadata for a URL and return it to the block editor's
 * Bookmark node. The request is proxied server-side so the editor never
 * hits cross-origin pages directly. Results are cached on disk for 24h to
 * keep the edit experience snappy and to avoid hammering origin servers.
 *
 * SSRF-hardened: private/loopback/link-local IPs are rejected both
 * up-front *and* after DNS resolution, and only http/https is accepted.
 * Redirects are disabled so an attacker can't bounce us back to internal
 * hosts after the initial check.
 */
class OgpController
{
    public function resolve(): void
    {
        header('Content-Type: application/json');

        $url = trim((string) ($_GET['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid URL']);
            return;
        }

        $rejection = \TypeDock\Http\UrlGuard::reject($url);
        if ($rejection !== null) {
            http_response_code(400);
            echo json_encode(['error' => $rejection]);
            return;
        }

        $cacheKey = 'ogp_' . md5($url);
        if ($cached = $this->cacheGet($cacheKey)) {
            echo json_encode($cached);
            return;
        }

        $html = $this->fetch($url);
        if ($html === null) {
            http_response_code(502);
            echo json_encode(['error' => 'Fetch failed', 'title' => $url]);
            return;
        }

        $ogp = $this->parse($html, $url);
        $this->cachePut($cacheKey, $ogp, 86400);
        echo json_encode($ogp);
    }

    /**
     * @return array{title: string, description: ?string, image: ?string, favicon: ?string}
     */
    private function parse(string $html, string $pageUrl): array
    {
        $out = ['title' => $pageUrl, 'description' => null, 'image' => null, 'favicon' => null];

        // og:* + twitter:* meta tags.
        $meta = [];
        if (preg_match_all(
            '/<meta\s+[^>]*(?:property|name)\s*=\s*["\']((?:og|twitter):[^"\']+)["\']\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/i',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $meta[strtolower($m[1])] = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        // Reverse-order variant: content before property.
        if (preg_match_all(
            '/<meta\s+[^>]*content\s*=\s*["\']([^"\']*)["\']\s+[^>]*(?:property|name)\s*=\s*["\']((?:og|twitter):[^"\']+)["\'][^>]*>/i',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $key = strtolower($m[2]);
                if (!isset($meta[$key])) {
                    $meta[$key] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? null;
        if ($title === null && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($title !== null && $title !== '') $out['title'] = $title;

        $desc = $meta['og:description'] ?? $meta['twitter:description'] ?? null;
        if ($desc === null && preg_match('/<meta\s+[^>]*name\s*=\s*["\']description["\']\s+[^>]*content\s*=\s*["\']([^"\']*)["\'][^>]*>/i', $html, $m)) {
            $desc = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($desc !== null && $desc !== '') $out['description'] = $desc;

        $image = $meta['og:image'] ?? $meta['og:image:secure_url'] ?? $meta['twitter:image'] ?? null;
        if ($image !== null && $image !== '') $out['image'] = $this->absolutize($image, $pageUrl);

        // favicon: rel="icon" | rel="shortcut icon"
        if (preg_match('/<link\s+[^>]*rel\s*=\s*["\'](?:shortcut\s+icon|icon)["\'][^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
            $out['favicon'] = $this->absolutize($m[1], $pageUrl);
        } else {
            $host = parse_url($pageUrl, PHP_URL_HOST);
            if ($host) $out['favicon'] = ($scheme = parse_url($pageUrl, PHP_URL_SCHEME) ?: 'https') . '://' . $host . '/favicon.ico';
        }

        return $out;
    }

    private function absolutize(string $ref, string $base): string
    {
        if (preg_match('/^https?:\/\//i', $ref)) return $ref;
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        if (str_starts_with($ref, '//')) return $scheme . ':' . $ref;
        if (str_starts_with($ref, '/'))  return $scheme . '://' . $host . $ref;
        $path = $parts['path'] ?? '/';
        $dir  = substr($path, 0, strrpos($path, '/') + 1);
        return $scheme . '://' . $host . $dir . $ref;
    }

    private function fetch(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: TypeDockBot/1.0 (+https://typedock.io)\r\nAccept: text/html,application/xhtml+xml\r\n",
                'timeout'       => 5,
                'follow_location' => 0, // avoid SSRF via redirect
                'max_redirects' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx, 0, 512 * 1024);
        if ($res === false) return null;
        return $res;
    }

    private function cacheDir(): string
    {
        $dir = TYPEDOCK_ROOT . '/storage/cache/ogp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cacheGet(string $key): ?array
    {
        $path = $this->cacheDir() . '/' . $key . '.json';
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false) return null;
        $row = json_decode($raw, true);
        if (!is_array($row) || !isset($row['expires_at'], $row['payload'])) return null;
        if ((int) $row['expires_at'] < time()) {
            @unlink($path);
            return null;
        }
        return is_array($row['payload']) ? $row['payload'] : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function cachePut(string $key, array $payload, int $ttl): void
    {
        $path = $this->cacheDir() . '/' . $key . '.json';
        $row  = ['expires_at' => time() + $ttl, 'payload' => $payload];
        @file_put_contents($path, json_encode($row));
    }
}
