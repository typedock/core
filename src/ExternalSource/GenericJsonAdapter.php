<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

final class GenericJsonAdapter implements ExternalSourceAdapterInterface
{
    public function metadata(): ExternalSourceAdapterMetadata
    {
        return new ExternalSourceAdapterMetadata(
            id: 'generic_json',
            label: 'Generic JSON',
            description: 'Read-only JSON endpoint with simple list and detail mapping',
            tokenRequired: false,
            tokenLabel: 'API token',
            tokenHelp: 'Optional. Use Bearer or Basic auth for a small proxy or simple JSON API. Complex signatures and OAuth should be handled by a proxy.',
            configFields: [
                ['name' => 'json_list_url', 'label' => 'List endpoint URL', 'type' => 'text', 'required' => true, 'placeholder' => 'https://api.example.com/items'],
                ['name' => 'json_detail_url', 'label' => 'Detail endpoint URL', 'type' => 'text', 'required' => false, 'placeholder' => 'https://api.example.com/items/{slug}', 'hint' => 'Optional. Use {slug}. If blank, TypeDock filters the list response by the slug field.'],
                ['name' => 'json_items_path', 'label' => 'Items path', 'type' => 'text', 'required' => false, 'placeholder' => 'items', 'hint' => 'Dotted path to the array. Leave blank when the response itself is an array.'],
                ['name' => 'json_total_path', 'label' => 'Total path', 'type' => 'text', 'required' => false, 'placeholder' => 'total'],
                [
                    'name' => 'json_auth_mode',
                    'label' => 'Auth mode',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => 'none', 'label' => 'None'],
                        ['value' => 'bearer', 'label' => 'Bearer token'],
                        ['value' => 'basic', 'label' => 'Basic auth'],
                    ],
                ],
                ['name' => 'json_basic_username', 'label' => 'Basic auth username', 'type' => 'text', 'required' => false, 'placeholder' => 'api-user', 'hint' => 'Required only when Auth mode is Basic auth. The API token field is used as the password.'],
                ['name' => 'json_slug_field', 'label' => 'Slug field', 'type' => 'text', 'required' => false, 'placeholder' => 'slug'],
            ],
            defaultConfig: [
                'json_list_url' => '',
                'json_detail_url' => '',
                'json_items_path' => '',
                'json_total_path' => 'total',
                'json_auth_mode' => 'none',
                'json_basic_username' => '',
                'json_slug_field' => 'slug',
            ],
            defaultMapping: [
                'slug' => 'slug',
                'title' => 'title',
                'excerpt' => 'excerpt',
                'thumbnail' => 'image',
                'date' => 'date',
                'category' => 'category',
                'tags' => 'tags',
                'content' => 'content',
            ],
            defaultDetailTemplate: "[resource.content]\n\nSource: [resource.raw.fields.url|url]",
        );
    }

    public function list(array $source, array $credentials, int $limit, int $offset = 0): array
    {
        $config = $this->config($source);
        $url = $this->withQuery($config['list_url'], [
            'limit' => max(1, min(100, $limit)),
            'offset' => max(0, $offset),
        ]);
        $json = $this->request($config, $credentials, $url);
        $items = $this->itemsFromResponse($json, $config['items_path']);
        $items = array_slice($items, 0, max(1, min(100, $limit)));

        return [
            'items' => array_map(fn (array $item): array => $this->normalizeItem($item, $config), $items),
            'total' => $this->totalFromResponse($json, $config['total_path'], $offset + count($items)),
        ];
    }

    public function getBySlug(array $source, array $credentials, string $slug): ?array
    {
        $config = $this->config($source);
        if ($config['detail_url'] !== '') {
            $json = $this->request($config, $credentials, str_replace('{slug}', rawurlencode($slug), $config['detail_url']));
            return is_array($json) ? $this->normalizeItem($this->unwrapItem($json, $config['items_path']), $config) : null;
        }

        $json = $this->request($config, $credentials, $config['list_url']);
        foreach ($this->itemsFromResponse($json, $config['items_path']) as $item) {
            if ($this->stringValue($this->path($item, $config['slug_field'])) === $slug) {
                return $this->normalizeItem($item, $config);
            }
        }
        return null;
    }

    public function discoverFields(array $source, array $credentials): array
    {
        $result = $this->list($source, $credentials, 1, 0);
        $fields = is_array($result['items'][0]['fields'] ?? null) ? $result['items'][0]['fields'] : [];
        $out = [];
        foreach ($this->flattenFields($fields) as $id => $value) {
            $out[] = [
                'id' => $id,
                'name' => $id,
                'type' => $this->typeName($value),
                'items_type' => '',
                'link_type' => '',
                'localized' => false,
                'required' => in_array($id, ['slug', 'title'], true),
                'disabled' => false,
                'omitted' => false,
                'suggested_mapping' => $this->suggestMapping($id, $value),
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $source
     * @return array{list_url:string,detail_url:string,items_path:string,total_path:string,auth_mode:string,basic_username:string,slug_field:string}
     */
    private function config(array $source): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $listUrl = trim((string) ($config['json_list_url'] ?? ''));
        if ($listUrl === '') {
            throw new \RuntimeException('Generic JSON source requires a list endpoint URL.');
        }
        $authMode = trim((string) ($config['json_auth_mode'] ?? 'none')) ?: 'none';
        if (!in_array($authMode, ['none', 'bearer', 'basic'], true)) {
            $authMode = 'none';
        }
        return [
            'list_url' => $listUrl,
            'detail_url' => trim((string) ($config['json_detail_url'] ?? '')),
            'items_path' => trim((string) ($config['json_items_path'] ?? '')),
            'total_path' => trim((string) ($config['json_total_path'] ?? 'total')),
            'auth_mode' => $authMode,
            'basic_username' => trim((string) ($config['json_basic_username'] ?? '')),
            'slug_field' => trim((string) ($config['json_slug_field'] ?? 'slug')) ?: 'slug',
        ];
    }

    /**
     * @param array{list_url:string,detail_url:string,items_path:string,total_path:string,auth_mode:string,basic_username:string,slug_field:string} $config
     * @param array<string, mixed> $credentials
     * @return mixed
     */
    private function request(array $config, array $credentials, string $url): mixed
    {
        $this->assertPublicHttpUrl($url);
        $headers = [
            'Accept: application/json',
            'User-Agent: TypeDock/0.1 ExternalSource',
        ];
        $token = trim((string) ($credentials['delivery_token'] ?? ''));
        if ($config['auth_mode'] === 'bearer') {
            if ($token === '') {
                throw new \RuntimeException('Generic JSON Bearer auth requires an API token.');
            }
            $headers[] = 'Authorization: Bearer ' . $token;
        } elseif ($config['auth_mode'] === 'basic') {
            if ($config['basic_username'] === '' || $token === '') {
                throw new \RuntimeException('Generic JSON Basic auth requires a username and API token.');
            }
            $headers[] = 'Authorization: Basic ' . base64_encode($config['basic_username'] . ':' . $token);
        }

        $body = $this->httpGet($url, $headers);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Generic JSON endpoint returned invalid JSON.');
        }
        return $json;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array<string, mixed>>
     */
    private function itemsFromResponse(mixed $json, string $itemsPath): array
    {
        $items = $itemsPath !== '' ? $this->path($json, $itemsPath) : $json;
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function unwrapItem(array $json, string $itemsPath): array
    {
        if ($itemsPath === '') {
            return $json;
        }
        $value = $this->path($json, $itemsPath);
        if (is_array($value) && array_is_list($value)) {
            return is_array($value[0] ?? null) ? $value[0] : [];
        }
        return is_array($value) ? $value : $json;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item, array $config): array
    {
        $slug = $this->stringValue($this->path($item, $config['slug_field']));
        $id = $this->stringValue($item['id'] ?? $slug);
        return [
            'sys' => [
                'id' => $id !== '' ? $id : sha1(json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'updatedAt' => $this->stringValue($item['updated_at'] ?? $item['updatedAt'] ?? $item['modified'] ?? ''),
            ],
            'fields' => ['slug' => $slug] + $item,
        ];
    }

    private function totalFromResponse(mixed $json, string $path, int $fallback): int
    {
        $value = $path !== '' ? $this->path($json, $path) : null;
        return is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function withQuery(string $url, array $query): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    private function assertPublicHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('Generic JSON endpoint must be an http(s) URL.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Generic JSON endpoint must not include credentials in the URL.');
        }
        if ($this->isBlockedHost($host)) {
            throw new \RuntimeException('Generic JSON endpoint host is not allowed.');
        }
        foreach ($this->resolveHostIps($host) as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw new \RuntimeException('Generic JSON endpoint resolves to a private or reserved IP address.');
            }
        }
    }

    private function isBlockedHost(string $host): bool
    {
        return $host === 'localhost' || str_ends_with($host, '.localhost');
    }

    /**
     * @return array<int, string>
     */
    private function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records) && $records !== []) {
            $ips = [];
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    if (isset($record[$key]) && is_string($record[$key])) {
                        $ips[] = $record[$key];
                    }
                }
            }
            return array_values(array_unique($ips));
        }
        $fallback = @gethostbynamel($host);
        return is_array($fallback) ? $fallback : [];
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function path(mixed $value, string $path): mixed
    {
        foreach (explode('.', $path) as $part) {
            if ($part === '') {
                continue;
            }
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
                continue;
            }
            return null;
        }
        return $value;
    }

    private function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        return '';
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function flattenFields(array $fields, string $prefix = ''): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value) && !array_is_list($value)) {
                $out += $this->flattenFields($value, $path);
                continue;
            }
            $out[$path] = $value;
        }
        return $out;
    }

    private function typeName(mixed $value): string
    {
        if (is_array($value)) {
            return 'Array';
        }
        if (is_bool($value)) {
            return 'Boolean';
        }
        if (is_int($value)) {
            return 'Integer';
        }
        if (is_float($value)) {
            return 'Number';
        }
        return 'String';
    }

    private function suggestMapping(string $id, mixed $value): string
    {
        $lower = strtolower($id);
        if ($lower === 'slug' || str_ends_with($lower, '.slug')) {
            return 'slug';
        }
        if (in_array($lower, ['title', 'name'], true) || str_ends_with($lower, '.title')) {
            return 'title';
        }
        if (str_contains($lower, 'excerpt') || str_contains($lower, 'summary')) {
            return 'excerpt';
        }
        if (str_contains($lower, 'image') || str_contains($lower, 'thumbnail') || str_contains($lower, 'photo')) {
            return 'thumbnail';
        }
        if (str_contains($lower, 'date') || str_contains($lower, 'published')) {
            return 'date';
        }
        if (str_contains($lower, 'category')) {
            return 'category';
        }
        if (str_contains($lower, 'tag') || is_array($value)) {
            return 'tags';
        }
        if (in_array($lower, ['content', 'body', 'description'], true)) {
            return 'content';
        }
        return '';
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
                throw new \RuntimeException($error !== '' ? $error : 'Generic JSON request failed.');
            }
            if ($status < 200 || $status >= 300) {
                throw new GenericJsonRequestException($status, (string) $body);
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
        $status = $this->statusCodeFromHeaders($http_response_header);
        if ($body === false) {
            if ($status > 0) {
                throw new GenericJsonRequestException($status);
            }
            throw new \RuntimeException('Generic JSON request failed.');
        }
        if ($status >= 400) {
            throw new GenericJsonRequestException($status, (string) $body);
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
