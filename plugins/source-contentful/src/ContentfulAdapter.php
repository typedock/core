<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SourceContentful;

use TypeDock\ExternalSource\ExternalSourceAdapterInterface;
use TypeDock\ExternalSource\ExternalSourceAdapterMetadata;

final class ContentfulAdapter implements ExternalSourceAdapterInterface
{
    public function metadata(): ExternalSourceAdapterMetadata
    {
        return new ExternalSourceAdapterMetadata(
            id: 'contentful',
            label: 'Contentful',
            description: 'Contentful Content Delivery API',
            tokenRequired: true,
            tokenLabel: 'Delivery API token',
            tokenHelp: 'Use a Content Delivery API token. Preview and CMA tokens are not accepted.',
            configFields: [
                ['name' => 'space_id', 'label' => 'Space ID', 'type' => 'text', 'required' => true, 'placeholder' => ''],
                ['name' => 'environment_id', 'label' => 'Environment', 'type' => 'text', 'required' => false, 'placeholder' => 'master'],
                ['name' => 'content_type', 'label' => 'Content type', 'type' => 'text', 'required' => true, 'placeholder' => 'pageBlogPost'],
                ['name' => 'slug_field', 'label' => 'Slug field', 'type' => 'text', 'required' => false, 'placeholder' => 'slug'],
            ],
            defaultConfig: [
                'space_id' => '',
                'environment_id' => 'master',
                'content_type' => '',
                'slug_field' => 'slug',
            ],
            defaultMapping: [
                'slug' => 'slug',
                'title' => 'title',
                'excerpt' => 'excerpt',
                'thumbnail' => 'thumbnail',
                'date' => 'publishedAt',
                'category' => 'category',
                'tags' => 'tags',
                'content' => 'content',
            ],
            defaultDetailTemplate: "[resource.excerpt]\n\n[resource.content|richText]\n\nPublished: [resource.date|date:\"Y-m-d\"]",
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
        $json = $this->request($config, $credentials, 'entries', [
            'content_type' => $config['content_type'],
            'limit' => max(1, min(100, $limit)),
            'skip' => max(0, $offset),
            'order' => '-sys.updatedAt',
            'include' => 2,
        ]);

        $items = is_array($json['items'] ?? null) ? $json['items'] : [];
        $items = $this->resolveIncludes($items, is_array($json['includes'] ?? null) ? $json['includes'] : []);
        return [
            'items' => array_values(array_filter($items, 'is_array')),
            'total' => (int) ($json['total'] ?? count($items)),
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
        $slugField = (string) ($config['slug_field'] ?? 'slug');
        $json = $this->request($config, $credentials, 'entries', [
            'content_type' => $config['content_type'],
            'fields.' . $slugField => $slug,
            'limit' => 1,
            'include' => 2,
        ]);

        $items = is_array($json['items'] ?? null) ? $json['items'] : [];
        $items = $this->resolveIncludes($items, is_array($json['includes'] ?? null) ? $json['includes'] : []);
        $first = $items[0] ?? null;
        return is_array($first) ? $first : null;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     * @return array<int, array<string, mixed>>
     */
    public function discoverFields(array $source, array $credentials): array
    {
        $config = $this->config($source);
        $json = $this->request($config, $credentials, 'content_types/' . rawurlencode($config['content_type']));
        $fields = is_array($json['fields'] ?? null) ? $json['fields'] : [];

        $out = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $id = trim((string) ($field['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $items = is_array($field['items'] ?? null) ? $field['items'] : [];
            $out[] = [
                'id' => $id,
                'name' => (string) ($field['name'] ?? $id),
                'type' => (string) ($field['type'] ?? ''),
                'items_type' => (string) ($items['type'] ?? ''),
                'link_type' => (string) ($field['linkType'] ?? $items['linkType'] ?? ''),
                'localized' => (bool) ($field['localized'] ?? false),
                'required' => (bool) ($field['required'] ?? false),
                'disabled' => (bool) ($field['disabled'] ?? false),
                'omitted' => (bool) ($field['omitted'] ?? false),
                'suggested_mapping' => $this->suggestMapping($field),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $source
     * @return array{space_id:string,environment_id:string,content_type:string,slug_field:string}
     */
    private function config(array $source): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $spaceId = trim((string) ($config['space_id'] ?? ''));
        $contentType = trim((string) ($config['content_type'] ?? ''));
        if ($spaceId === '' || $contentType === '') {
            throw new \RuntimeException('Contentful source requires space_id and content_type.');
        }

        return [
            'space_id' => $spaceId,
            'environment_id' => trim((string) ($config['environment_id'] ?? 'master')) ?: 'master',
            'content_type' => $contentType,
            'slug_field' => trim((string) ($config['slug_field'] ?? 'slug')) ?: 'slug',
        ];
    }

    /**
     * @param array{space_id:string,environment_id:string,content_type:string,slug_field:string} $config
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function request(array $config, array $credentials, string $path, array $query = []): array
    {
        $token = trim((string) ($credentials['delivery_token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('Contentful delivery token is missing.');
        }

        $url = 'https://cdn.contentful.com/spaces/' . rawurlencode($config['space_id'])
            . '/environments/' . rawurlencode($config['environment_id'])
            . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: TypeDock/0.1 ExternalSource',
        ];

        $body = $this->httpGet($url, $headers);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Contentful returned invalid JSON.');
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function suggestMapping(array $field): string
    {
        $id = strtolower((string) ($field['id'] ?? ''));
        $name = strtolower((string) ($field['name'] ?? ''));
        $type = (string) ($field['type'] ?? '');
        $linkType = (string) ($field['linkType'] ?? '');

        if ($id === 'slug' || str_ends_with($id, 'slug')) {
            return 'slug';
        }
        if (in_array($id, ['title', 'name', 'internalname'], true) || str_contains($name, 'title')) {
            return 'title';
        }
        if (str_contains($id, 'excerpt') || str_contains($id, 'summary') || str_contains($id, 'subtitle')) {
            return 'excerpt';
        }
        if ($type === 'Link' && $linkType === 'Asset') {
            return 'thumbnail';
        }
        if (str_contains($id, 'image') || str_contains($id, 'thumbnail') || str_contains($id, 'photo')) {
            return 'thumbnail';
        }
        if ($type === 'Date' || str_contains($id, 'date') || str_contains($id, 'published')) {
            return 'date';
        }
        if (str_contains($id, 'category')) {
            return 'category';
        }
        if (str_contains($id, 'tag')) {
            return 'tags';
        }
        if ($type === 'RichText' || in_array($id, ['content', 'body'], true)) {
            return 'content';
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $includes
     * @return array<int, array<string, mixed>>
     */
    private function resolveIncludes(array $items, array $includes): array
    {
        $assets = [];
        foreach ((array) ($includes['Asset'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $id = (string) ($asset['sys']['id'] ?? '');
            if ($id !== '') {
                $assets[$id] = $this->normalizeAsset($asset);
            }
        }

        $entries = [];
        foreach ((array) ($includes['Entry'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (string) ($entry['sys']['id'] ?? '');
            if ($id !== '') {
                $entries[$id] = $entry;
            }
        }

        return array_map(
            fn (array $item): array => $this->resolveValue($item, $assets, $entries, 0),
            $items
        );
    }

    /**
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    private function normalizeAsset(array $asset): array
    {
        $fields = is_array($asset['fields'] ?? null) ? $asset['fields'] : [];
        $file = is_array($fields['file'] ?? null) ? $fields['file'] : [];
        $details = is_array($file['details'] ?? null) ? $file['details'] : [];
        $image = is_array($details['image'] ?? null) ? $details['image'] : [];
        $url = $this->absoluteAssetUrl((string) ($file['url'] ?? ''));

        return [
            'sys' => $asset['sys'] ?? [],
            'fields' => $fields,
            'title' => (string) ($fields['title'] ?? ''),
            'description' => (string) ($fields['description'] ?? ''),
            'url' => $url,
            'contentType' => (string) ($file['contentType'] ?? ''),
            'width' => (int) ($image['width'] ?? 0),
            'height' => (int) ($image['height'] ?? 0),
        ];
    }

    private function absoluteAssetUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return 'https://' . ltrim($url, '/');
        }
        return $url;
    }

    /**
     * @param array<string, array<string, mixed>> $assets
     * @param array<string, array<string, mixed>> $entries
     * @return mixed
     */
    private function resolveValue(mixed $value, array $assets, array $entries, int $depth): mixed
    {
        if ($depth > 4) {
            return $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $sys = is_array($value['sys'] ?? null) ? $value['sys'] : null;
        if ($sys !== null && ($sys['type'] ?? '') === 'Link') {
            $id = (string) ($sys['id'] ?? '');
            $linkType = (string) ($sys['linkType'] ?? '');
            if ($linkType === 'Asset' && isset($assets[$id])) {
                return $assets[$id];
            }
            if ($linkType === 'Entry' && isset($entries[$id])) {
                return $this->resolveValue($entries[$id], $assets, $entries, $depth + 1);
            }
            return $value;
        }

        $out = [];
        foreach ($value as $key => $child) {
            $out[$key] = $this->resolveValue($child, $assets, $entries, $depth + 1);
        }
        return $out;
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
                throw new \RuntimeException($error !== '' ? $error : 'Contentful request failed.');
            }
            if ($status < 200 || $status >= 300) {
                throw new ContentfulRequestException($status, (string) $body);
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
                throw new ContentfulRequestException($status);
            }
            throw new \RuntimeException('Contentful request failed.');
        }
        if ($status >= 400) {
            throw new ContentfulRequestException($status, (string) $body);
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
