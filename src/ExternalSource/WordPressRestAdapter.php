<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

final class WordPressRestAdapter implements ExternalSourceAdapterInterface
{
    public function metadata(): ExternalSourceAdapterMetadata
    {
        return new ExternalSourceAdapterMetadata(
            id: 'wordpress_rest',
            label: 'WordPress REST API',
            description: 'Read-only posts, pages, or custom post types from a WordPress REST API',
            tokenRequired: false,
            tokenLabel: 'Application password or Bearer token',
            tokenHelp: 'Optional for public content. Use a WordPress Application Password with Basic auth, or a proxy-provided Bearer token for private APIs.',
            configFields: [
                ['name' => 'wp_site_url', 'label' => 'Site URL', 'type' => 'text', 'required' => true, 'placeholder' => 'https://example.com'],
                ['name' => 'wp_rest_base', 'label' => 'REST base path', 'type' => 'text', 'required' => false, 'placeholder' => '/wp-json/wp/v2'],
                ['name' => 'wp_resource_type', 'label' => 'Resource type', 'type' => 'text', 'required' => true, 'placeholder' => 'posts'],
                [
                    'name' => 'wp_auth_mode',
                    'label' => 'Auth mode',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => 'none', 'label' => 'None'],
                        ['value' => 'basic', 'label' => 'Basic application password'],
                        ['value' => 'bearer', 'label' => 'Bearer token'],
                    ],
                ],
                ['name' => 'wp_username', 'label' => 'Username', 'type' => 'text', 'required' => false, 'placeholder' => 'editor', 'hint' => 'Required only for Basic application password auth.'],
                [
                    'name' => 'wp_status',
                    'label' => 'Status',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => 'publish', 'label' => 'Published'],
                        ['value' => 'any', 'label' => 'Any'],
                    ],
                ],
                [
                    'name' => 'wp_embed',
                    'label' => 'Embed media and terms',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => '1', 'label' => 'Yes'],
                        ['value' => '0', 'label' => 'No'],
                    ],
                ],
                ['name' => 'wp_locale_param', 'label' => 'Locale parameter', 'type' => 'text', 'required' => false, 'placeholder' => 'lang', 'hint' => 'Optional. Used by plugins such as Polylang when available.'],
                ['name' => 'wp_locale', 'label' => 'Locale value', 'type' => 'text', 'required' => false, 'placeholder' => 'en'],
            ],
            defaultConfig: [
                'wp_site_url' => '',
                'wp_rest_base' => '/wp-json/wp/v2',
                'wp_resource_type' => 'posts',
                'wp_auth_mode' => 'none',
                'wp_username' => '',
                'wp_status' => 'publish',
                'wp_embed' => '1',
                'wp_locale_param' => '',
                'wp_locale' => '',
            ],
            defaultMapping: [
                'slug' => 'slug',
                'title' => 'title',
                'excerpt' => 'excerpt',
                'thumbnail' => 'featured_image_url',
                'date' => 'date',
                'category' => 'type',
                'tags' => 'tags',
                'content' => 'content',
            ],
            defaultDetailTemplate: "[resource.content]\n\nSource: [resource.raw.fields.link|url]",
        );
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function list(array $source, array $credentials, int $limit, int $offset = 0): array
    {
        $config = $this->config($source);
        $perPage = max(1, min(100, $limit));
        $page = intdiv(max(0, $offset), $perPage) + 1;
        $query = [
            'per_page' => $perPage,
            'page' => $page,
            'orderby' => 'date',
            'order' => 'desc',
        ];
        if ($config['status'] !== 'any') {
            $query['status'] = $config['status'];
        }
        if ($config['embed']) {
            $query['_embed'] = '1';
        }
        $query += $this->localeQuery($config);

        $json = $this->request($config, $credentials, $config['resource_type'], $query);
        $items = is_array($json) ? $json : [];
        $posts = [];
        foreach ($items as $post) {
            if (is_array($post)) {
                $posts[] = $this->normalizePost($post);
            }
        }

        return [
            'items' => $posts,
            'total' => $offset + count($posts) + (count($posts) === $perPage ? 1 : 0),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>|null
     */
    public function getBySlug(array $source, array $credentials, string $slug): ?array
    {
        $config = $this->config($source);
        $query = [];
        if ($config['embed']) {
            $query['_embed'] = '1';
        }
        $query += $this->localeQuery($config);

        if (preg_match('/^post-([1-9][0-9]*)$/', $slug, $matches)) {
            $post = $this->request($config, $credentials, $config['resource_type'] . '/' . rawurlencode($matches[1]), $query);
            return is_array($post) ? $this->normalizePost($post) : null;
        }

        $query['slug'] = $slug;
        $query['per_page'] = 1;
        if ($config['status'] !== 'any') {
            $query['status'] = $config['status'];
        }

        $items = $this->request($config, $credentials, $config['resource_type'], $query);
        $first = is_array($items) ? ($items[0] ?? null) : null;
        return is_array($first) ? $this->normalizePost($first) : null;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     * @return array<int, array<string, mixed>>
     */
    public function discoverFields(array $source, array $credentials): array
    {
        $this->list($source, $credentials, 1, 0);

        return [
            $this->field('slug', 'Public slug', 'String', 'slug'),
            $this->field('id', 'WordPress ID', 'Integer', ''),
            $this->field('title', 'Title', 'String', 'title'),
            $this->field('excerpt', 'Excerpt', 'Text', 'excerpt'),
            $this->field('content', 'Content text', 'Text', 'content'),
            $this->field('featured_image_url', 'Featured image URL', 'URL', 'thumbnail'),
            $this->field('date', 'Published date', 'Date', 'date'),
            $this->field('modified', 'Modified date', 'Date', ''),
            $this->field('type', 'Post type', 'String', 'category'),
            $this->field('status', 'Status', 'String', ''),
            $this->field('categories', 'Categories', 'Array', 'category'),
            $this->field('tags', 'Tags', 'Array', 'tags'),
            $this->field('link', 'WordPress URL', 'URL', ''),
            $this->field('author', 'Author ID', 'Integer', ''),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{site_url:string,rest_base:string,resource_type:string,auth_mode:string,username:string,status:string,embed:bool,locale_param:string,locale:string}
     */
    private function config(array $source): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $siteUrl = $this->normalizeSiteUrl((string) ($config['wp_site_url'] ?? ''));
        if ($siteUrl === '') {
            throw new \RuntimeException('WordPress source requires a site URL.');
        }

        $authMode = trim((string) ($config['wp_auth_mode'] ?? 'none')) ?: 'none';
        if (!in_array($authMode, ['none', 'basic', 'bearer'], true)) {
            $authMode = 'none';
        }

        $status = trim((string) ($config['wp_status'] ?? 'publish')) ?: 'publish';
        if (!in_array($status, ['publish', 'any'], true)) {
            $status = 'publish';
        }

        return [
            'site_url' => $siteUrl,
            'rest_base' => '/' . trim((string) ($config['wp_rest_base'] ?? '/wp-json/wp/v2'), '/'),
            'resource_type' => trim((string) ($config['wp_resource_type'] ?? 'posts'), "/ \t\n\r\0\x0B") ?: 'posts',
            'auth_mode' => $authMode,
            'username' => trim((string) ($config['wp_username'] ?? '')),
            'status' => $status,
            'embed' => (string) ($config['wp_embed'] ?? '1') !== '0',
            'locale_param' => trim((string) ($config['wp_locale_param'] ?? '')),
            'locale' => trim((string) ($config['wp_locale'] ?? '')),
        ];
    }

    /**
     * @param array{site_url:string,rest_base:string,resource_type:string,auth_mode:string,username:string,status:string,embed:bool,locale_param:string,locale:string} $config
     * @return array<string, string>
     */
    private function localeQuery(array $config): array
    {
        if ($config['locale_param'] === '' || $config['locale'] === '') {
            return [];
        }
        return [$config['locale_param'] => $config['locale']];
    }

    private function normalizeSiteUrl(string $siteUrl): string
    {
        $siteUrl = trim($siteUrl);
        if ($siteUrl === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $siteUrl)) {
            $siteUrl = 'https://' . $siteUrl;
        }
        return rtrim($siteUrl, '/');
    }

    /**
     * @param array{site_url:string,rest_base:string,resource_type:string,auth_mode:string,username:string,status:string,embed:bool,locale_param:string,locale:string} $config
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $query
     * @return mixed
     */
    private function request(array $config, array $credentials, string $path, array $query = []): mixed
    {
        $url = $config['site_url'] . $config['rest_base'] . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/json',
            'User-Agent: TypeDock/0.1 ExternalSource',
        ];
        $token = trim((string) ($credentials['delivery_token'] ?? ''));
        if ($config['auth_mode'] === 'basic') {
            $token = preg_replace('/\s+/', '', $token) ?? '';
            if ($config['username'] === '' || $token === '') {
                throw new \RuntimeException('WordPress Basic auth requires a username and application password.');
            }
            $headers[] = 'Authorization: Basic ' . base64_encode($config['username'] . ':' . $token);
        } elseif ($config['auth_mode'] === 'bearer') {
            if ($token === '') {
                throw new \RuntimeException('WordPress Bearer auth requires a token.');
            }
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $body = $this->httpGet($url, $headers);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('WordPress returned invalid JSON.');
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function normalizePost(array $post): array
    {
        $id = (string) ($post['id'] ?? '');
        $title = $this->renderedText($post['title'] ?? null);
        $excerpt = $this->renderedText($post['excerpt'] ?? null);
        $content = $this->renderedText($post['content'] ?? null);
        $terms = $this->embeddedTerms($post);

        return [
            'sys' => [
                'id' => $id,
                'updatedAt' => (string) ($post['modified_gmt'] ?? $post['modified'] ?? ''),
            ],
            'fields' => [
                'id' => $post['id'] ?? null,
                'slug' => trim((string) ($post['slug'] ?? '')) !== '' ? (string) $post['slug'] : 'post-' . $id,
                'title' => $title,
                'excerpt' => $excerpt,
                'content' => $content,
                'featured_image_url' => $this->featuredImageUrl($post),
                'date' => (string) ($post['date_gmt'] ?? $post['date'] ?? ''),
                'modified' => (string) ($post['modified_gmt'] ?? $post['modified'] ?? ''),
                'type' => (string) ($post['type'] ?? ''),
                'status' => (string) ($post['status'] ?? ''),
                'categories' => $terms['categories'],
                'tags' => $terms['tags'],
                'link' => (string) ($post['link'] ?? ''),
                'author' => $post['author'] ?? null,
            ] + $post,
        ];
    }

    private function renderedText(mixed $value): string
    {
        if (is_array($value) && isset($value['rendered']) && is_scalar($value['rendered'])) {
            $value = $value['rendered'];
        }
        if (!is_scalar($value)) {
            return '';
        }
        $text = (string) $value;
        $text = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', $text) ?? $text;
        $text = preg_replace('#<(iframe|form|object|embed)\b[^>]*>.*?</\1>#is', '', $text) ?? $text;
        $text = preg_replace('#<div\b[^>]*(?:id|class)=["\'][^"\']*(?:hbspt|hubspot|hs-form)[^"\']*["\'][^>]*>.*?</div>#is', '', $text) ?? $text;
        $text = preg_replace('#<(?:br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function featuredImageUrl(array $post): string
    {
        $media = $post['_embedded']['wp:featuredmedia'][0] ?? null;
        if (!is_array($media)) {
            return '';
        }
        $sizes = is_array($media['media_details']['sizes'] ?? null) ? $media['media_details']['sizes'] : [];
        foreach (['large', 'medium_large', 'full'] as $size) {
            $url = $sizes[$size]['source_url'] ?? null;
            if (is_scalar($url) && trim((string) $url) !== '') {
                return trim((string) $url);
            }
        }
        return is_scalar($media['source_url'] ?? null) ? trim((string) $media['source_url']) : '';
    }

    /**
     * @param array<string, mixed> $post
     * @return array{categories:array<int, string>,tags:array<int, string>}
     */
    private function embeddedTerms(array $post): array
    {
        $terms = [
            'categories' => [],
            'tags' => [],
        ];
        foreach ((array) ($post['_embedded']['wp:term'] ?? []) as $group) {
            foreach ((array) $group as $term) {
                if (!is_array($term)) {
                    continue;
                }
                $taxonomy = (string) ($term['taxonomy'] ?? '');
                $name = trim((string) ($term['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                if ($taxonomy === 'category') {
                    $terms['categories'][] = $name;
                } elseif ($taxonomy === 'post_tag') {
                    $terms['tags'][] = $name;
                }
            }
        }
        if ($terms['categories'] === []) {
            $terms['categories'] = array_map('strval', is_array($post['categories'] ?? null) ? $post['categories'] : []);
        }
        if ($terms['tags'] === []) {
            $terms['tags'] = array_map('strval', is_array($post['tags'] ?? null) ? $post['tags'] : []);
        }
        return $terms;
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $id, string $name, string $type, string $suggested): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'items_type' => '',
            'link_type' => '',
            'localized' => false,
            'required' => in_array($id, ['slug', 'title'], true),
            'disabled' => false,
            'omitted' => false,
            'suggested_mapping' => $suggested,
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function httpGet(string $url, array $headers): string
    {
        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Failed to initialize HTTP client.');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new \RuntimeException($error !== '' ? $error : 'WordPress request failed.');
            }
            if ($status < 200 || $status >= 300) {
                throw new WordPressRequestException($status, (string) $body);
            }
            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'header' => implode("\r\n", $headers),
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = $this->statusCodeFromHeaders($http_response_header ?? []);
        if ($body === false) {
            if ($status > 0) {
                throw new WordPressRequestException($status);
            }
            throw new \RuntimeException('WordPress request failed.');
        }
        if ($status >= 400) {
            throw new WordPressRequestException($status, (string) $body);
        }
        return $body;
    }

    /**
     * @param array<int, string> $headers
     */
    private function statusCodeFromHeaders(array $headers): int
    {
        $line = (string) ($headers[0] ?? '');
        if (preg_match('#\s([0-9]{3})\s#', $line, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }
}
