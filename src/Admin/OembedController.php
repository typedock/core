<?php
declare(strict_types=1);

namespace TypeDock\Admin;

/**
 * oEmbed proxy. The block editor's Embed node calls this to resolve
 * YouTube/Vimeo/X/etc. URLs to iframe markup without exposing the user's
 * browser to the provider's endpoints directly (and to keep CSRF-requiring
 * CSP rules consistent).
 *
 * We're strict about what goes back to the client:
 *   - only known provider domains in the request URL
 *   - `html` is allowlist-sanitised (iframe + blockquote only, iframe src
 *     restricted to the same provider's known embed host)
 *   - results cached on disk for 24h
 */
class OembedController
{
    /**
     * @var array<string, array{
     *     endpoint: string,
     *     iframe_hosts: array<int, string>
     * }>
     */
    private const PROVIDERS = [
        'youtube.com' => [
            'endpoint'     => 'https://www.youtube.com/oembed?url=%s&format=json',
            'iframe_hosts' => ['www.youtube.com', 'youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'],
        ],
        'youtu.be' => [
            'endpoint'     => 'https://www.youtube.com/oembed?url=%s&format=json',
            'iframe_hosts' => ['www.youtube.com', 'youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com'],
        ],
        'twitter.com' => [
            'endpoint'     => 'https://publish.twitter.com/oembed?url=%s',
            'iframe_hosts' => ['platform.twitter.com'],
        ],
        'x.com' => [
            'endpoint'     => 'https://publish.twitter.com/oembed?url=%s',
            'iframe_hosts' => ['platform.twitter.com'],
        ],
        'vimeo.com' => [
            'endpoint'     => 'https://vimeo.com/api/oembed.json?url=%s',
            'iframe_hosts' => ['player.vimeo.com'],
        ],
        'open.spotify.com' => [
            'endpoint'     => 'https://open.spotify.com/oembed?url=%s',
            'iframe_hosts' => ['open.spotify.com'],
        ],
        'soundcloud.com' => [
            'endpoint'     => 'https://soundcloud.com/oembed?format=json&url=%s',
            'iframe_hosts' => ['w.soundcloud.com'],
        ],
    ];

    public function resolve(): void
    {
        header('Content-Type: application/json');

        $url = trim((string) ($_GET['url'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid URL']);
            return;
        }

        $provider = $this->matchProvider($url);
        if ($provider === null) {
            http_response_code(404);
            echo json_encode(['error' => 'No oEmbed provider matches this URL']);
            return;
        }
        [$host, $config] = $provider;

        $cacheKey = 'oembed_' . md5($url);
        if ($cached = $this->cacheGet($cacheKey)) {
            echo json_encode($cached);
            return;
        }

        $endpoint = sprintf($config['endpoint'], rawurlencode($url));
        $response = $this->fetch($endpoint);
        if ($response === null) {
            http_response_code(502);
            echo json_encode(['error' => 'oEmbed request failed']);
            return;
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            http_response_code(502);
            echo json_encode(['error' => 'oEmbed response invalid']);
            return;
        }

        // Sanitise html before echoing.
        if (isset($data['html'])) {
            $data['html'] = $this->sanitizeHtml((string) $data['html'], $config['iframe_hosts']);
        }
        // Strip anything we don't want to surface to the editor.
        $out = [
            'type'          => $data['type']          ?? null,
            'version'       => $data['version']       ?? null,
            'provider_name' => $data['provider_name'] ?? $host,
            'title'         => $data['title']         ?? null,
            'html'          => $data['html']          ?? null,
            'width'         => $data['width']         ?? null,
            'height'        => $data['height']        ?? null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
        ];

        $this->cachePut($cacheKey, $out, 86400);
        echo json_encode($out);
    }

    /**
     * @return array{0: string, 1: array{endpoint: string, iframe_hosts: array<int, string>}}|null
     */
    private function matchProvider(string $url): ?array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') return null;

        foreach (self::PROVIDERS as $domain => $config) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return [$domain, $config];
            }
        }
        return null;
    }

    /**
     * Allowlist-sanitise the oEmbed `html` field. Keeps <iframe>, <blockquote>,
     * <a>, <p>, <br>. Iframe src must point at an allowed host. Everything
     * else is stripped. Script/style tags are always removed.
     *
     * @param array<int, string> $iframeHosts
     */
    private function sanitizeHtml(string $html, array $iframeHosts): string
    {
        // Short-circuit: drop <script> / <style> outright.
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? '';
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html) ?? '';

        // Parse with DOMDocument for tag-level allowlisting.
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__oembed_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if (!$loaded) return '';

        $root = $dom->getElementById('__oembed_root__');
        if ($root === null) return '';

        $this->sanitizeNode($root, $iframeHosts);

        // Extract inner HTML of root.
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    /**
     * @param array<int, string> $iframeHosts
     */
    private function sanitizeNode(\DOMNode $node, array $iframeHosts): void
    {
        $allowedTags = ['iframe', 'blockquote', 'a', 'p', 'br', 'div', 'span'];
        $allowedAttrs = [
            'iframe'     => ['src', 'width', 'height', 'frameborder', 'allow', 'allowfullscreen', 'title', 'loading', 'referrerpolicy'],
            'blockquote' => ['class', 'cite', 'data-lang', 'data-dnt'],
            'a'          => ['href', 'target', 'rel'],
            'p'          => ['class'],
            'br'         => [],
            'div'        => ['class'],
            'span'       => ['class'],
        ];

        $toRemove = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowedTags, true)) {
                    $toRemove[] = $child;
                    continue;
                }
                // Strip disallowed attributes.
                $allowed = $allowedAttrs[$tag] ?? [];
                $attrs = [];
                foreach ($child->attributes as $attr) $attrs[] = $attr->name;
                foreach ($attrs as $attr) {
                    if (!in_array($attr, $allowed, true)) {
                        $child->removeAttribute($attr);
                    }
                }
                if ($tag === 'iframe') {
                    $src = $child->getAttribute('src');
                    if (!$this->iframeSrcAllowed($src, $iframeHosts)) {
                        $toRemove[] = $child;
                        continue;
                    }
                    // Enforce safe defaults.
                    if (!$child->hasAttribute('referrerpolicy')) {
                        $child->setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
                    }
                    if (!$child->hasAttribute('loading')) {
                        $child->setAttribute('loading', 'lazy');
                    }
                }
                if ($tag === 'a') {
                    $href = $child->getAttribute('href');
                    if (preg_match('/^\s*(javascript|data|vbscript):/i', $href)) {
                        $child->removeAttribute('href');
                    }
                    $child->setAttribute('rel', 'noopener noreferrer');
                    $child->setAttribute('target', '_blank');
                }
                $this->sanitizeNode($child, $iframeHosts);
            }
        }
        foreach ($toRemove as $el) {
            $el->parentNode?->removeChild($el);
        }
    }

    /**
     * @param array<int, string> $iframeHosts
     */
    private function iframeSrcAllowed(string $src, array $iframeHosts): bool
    {
        if (!preg_match('#^https?://#i', $src)) return false;
        $host = strtolower((string) parse_url($src, PHP_URL_HOST));
        if ($host === '') return false;
        foreach ($iframeHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    private function fetch(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: TypeDockBot/1.0 (+https://typedock.io)\r\nAccept: application/json\r\n",
                'timeout'       => 5,
                'follow_location' => 1, // oEmbed endpoints may redirect
                'max_redirects' => 3,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx, 0, 256 * 1024);
        return $res === false ? null : $res;
    }

    private function cacheDir(): string
    {
        $dir = TYPEDOCK_ROOT . '/storage/cache/oembed';
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
