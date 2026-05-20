<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SourceGitHubDocs;

use TypeDock\ExternalSource\ExternalSourceAdapterInterface;
use TypeDock\ExternalSource\ExternalSourceAdapterMetadata;

final class GitHubDocsAdapter implements ExternalSourceAdapterInterface
{
    public function metadata(): ExternalSourceAdapterMetadata
    {
        return new ExternalSourceAdapterMetadata(
            id: 'github_docs',
            label: 'GitHub Markdown Docs',
            description: 'Read-only Markdown files from a GitHub repository directory',
            tokenRequired: false,
            tokenLabel: 'GitHub API token',
            tokenHelp: 'Optional for public repositories. Use a fine-grained token for private repositories or higher rate limits.',
            configFields: [
                ['name' => 'github_owner', 'label' => 'Owner', 'type' => 'text', 'required' => true, 'placeholder' => 'typedock'],
                ['name' => 'github_repo', 'label' => 'Repository', 'type' => 'text', 'required' => true, 'placeholder' => 'core'],
                ['name' => 'github_branch', 'label' => 'Branch', 'type' => 'text', 'required' => true, 'placeholder' => 'main'],
                ['name' => 'github_docs_path', 'label' => 'Docs path', 'type' => 'text', 'required' => true, 'placeholder' => 'docs', 'hint' => 'Directory containing Markdown files. Nested directories are supported.'],
            ],
            defaultConfig: [
                'github_owner' => '',
                'github_repo' => '',
                'github_branch' => 'main',
                'github_docs_path' => 'docs',
            ],
            defaultMapping: [
                'slug' => 'slug',
                'title' => 'title',
                'excerpt' => 'excerpt',
                'thumbnail' => '',
                'date' => 'date',
                'category' => 'category',
                'tags' => 'tags',
                'content' => 'content',
            ],
            defaultDetailTemplate: "[resource.content|markdown]\n\nSource: [resource.raw.fields.html_url|url]",
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
        $files = $this->markdownFiles($config, $credentials);
        $pageFiles = array_slice($files, max(0, $offset), max(1, min(100, $limit)));

        $items = [];
        foreach ($pageFiles as $file) {
            $items[] = $this->normalizeDocument($file, $this->fetchMarkdown($config, $credentials, (string) $file['path']), $config);
        }

        return [
            'items' => $items,
            'total' => count($files),
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
        foreach ($this->markdownFiles($config, $credentials) as $file) {
            $relativePath = $this->relativePath((string) $file['path'], $config['docs_path']);
            if ($this->slugFromPath($relativePath) !== $slug) {
                continue;
            }

            return $this->normalizeDocument($file, $this->fetchMarkdown($config, $credentials, (string) $file['path']), $config);
        }

        return null;
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
            $this->field('title', 'Title', 'String', 'title'),
            $this->field('excerpt', 'Excerpt', 'Text', 'excerpt'),
            $this->field('content', 'Markdown content', 'Markdown', 'content'),
            $this->field('path', 'Repository path', 'String', ''),
            $this->field('relative_path', 'Docs-relative path', 'String', ''),
            $this->field('html_url', 'GitHub URL', 'URL', ''),
            $this->field('date', 'Front matter date', 'Date', 'date'),
            $this->field('category', 'Directory', 'String', 'category'),
            $this->field('tags', 'Front matter tags', 'Array', 'tags'),
            $this->field('sha', 'Git blob SHA', 'String', ''),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{owner:string,repo:string,branch:string,docs_path:string}
     */
    private function config(array $source): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $owner = trim((string) ($config['github_owner'] ?? ''));
        $repo = trim((string) ($config['github_repo'] ?? ''));
        $branch = trim((string) ($config['github_branch'] ?? 'main')) ?: 'main';
        $docsPath = trim((string) ($config['github_docs_path'] ?? 'docs'), "/ \t\n\r\0\x0B") ?: 'docs';

        if ($owner === '' || $repo === '') {
            throw new \RuntimeException('GitHub docs source requires owner and repository.');
        }

        return [
            'owner' => $owner,
            'repo' => $repo,
            'branch' => $branch,
            'docs_path' => $docsPath,
        ];
    }

    /**
     * @param array{owner:string,repo:string,branch:string,docs_path:string} $config
     * @param array<string, mixed> $credentials
     * @return array<int, array<string, mixed>>
     */
    private function markdownFiles(array $config, array $credentials): array
    {
        $json = $this->requestJson($config, $credentials, 'git/trees/' . rawurlencode($config['branch']), [
            'recursive' => '1',
        ]);
        $tree = is_array($json['tree'] ?? null) ? $json['tree'] : [];
        $prefix = trim($config['docs_path'], '/');
        $files = [];

        foreach ($tree as $node) {
            if (!is_array($node) || ($node['type'] ?? '') !== 'blob') {
                continue;
            }

            $path = trim((string) ($node['path'] ?? ''), '/');
            if ($path === '' || !$this->isMarkdownPath($path)) {
                continue;
            }
            if ($prefix !== '' && $path !== $prefix && !str_starts_with($path, $prefix . '/')) {
                continue;
            }

            $files[] = $node + ['path' => $path];
        }

        usort($files, fn (array $a, array $b): int => strnatcasecmp((string) $a['path'], (string) $b['path']));
        return $files;
    }

    /**
     * @param array{owner:string,repo:string,branch:string,docs_path:string} $config
     * @param array<string, mixed> $credentials
     */
    private function fetchMarkdown(array $config, array $credentials, string $path): string
    {
        $json = $this->requestJson($config, $credentials, 'contents/' . str_replace('%2F', '/', rawurlencode($path)), [
            'ref' => $config['branch'],
        ]);
        $content = (string) ($json['content'] ?? '');
        $encoding = strtolower((string) ($json['encoding'] ?? ''));

        if ($encoding !== 'base64' || $content === '') {
            throw new \RuntimeException('GitHub did not return base64 Markdown content for ' . $path . '.');
        }

        $decoded = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);
        if ($decoded === false) {
            throw new \RuntimeException('GitHub returned invalid base64 Markdown content for ' . $path . '.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $file
     * @param array{owner:string,repo:string,branch:string,docs_path:string} $config
     * @return array<string, mixed>
     */
    private function normalizeDocument(array $file, string $markdown, array $config): array
    {
        $path = trim((string) ($file['path'] ?? ''), '/');
        $relativePath = $this->relativePath($path, $config['docs_path']);
        $metadata = $this->markdownMetadata($markdown, $relativePath);
        $sha = trim((string) ($file['sha'] ?? ''));

        return [
            'sys' => [
                'id' => $sha !== '' ? $sha : sha1($path),
                'updatedAt' => $metadata['date'],
            ],
            'fields' => [
                'slug' => $this->slugFromPath($relativePath),
                'title' => $metadata['title'],
                'excerpt' => $metadata['excerpt'],
                'content' => $metadata['content'],
                'path' => $path,
                'relative_path' => $relativePath,
                'name' => basename($path),
                'html_url' => $this->htmlUrl($config, $path),
                'date' => $metadata['date'],
                'category' => $this->categoryFromPath($relativePath),
                'tags' => $metadata['tags'],
                'sha' => $sha,
            ],
        ];
    }

    /**
     * @return array{title:string,excerpt:string,content:string,date:string,tags:array<int,string>}
     */
    private function markdownMetadata(string $markdown, string $relativePath): array
    {
        [$frontMatter, $body] = $this->splitFrontMatter($markdown);
        $front = $this->parseFrontMatter($frontMatter);
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));

        $title = (string) ($front['title'] ?? '');
        if ($title === '' && preg_match('/^\s*#\s+(.+)$/m', $body, $matches)) {
            $title = $this->plainText($matches[1]);
        }
        if ($title === '') {
            $title = $this->titleFromPath($relativePath);
        }

        $content = preg_replace('/^\s*#\s+.+(?:\n+|$)/', '', $body, 1) ?? $body;
        $content = trim($content);

        $excerpt = (string) ($front['description'] ?? '');
        if ($excerpt === '') {
            $excerpt = $this->firstParagraph($content);
        }

        return [
            'title' => $title,
            'excerpt' => $excerpt,
            'content' => $content,
            'date' => (string) ($front['date'] ?? ''),
            'tags' => $this->tagsFromFrontMatter($front['tags'] ?? ''),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitFrontMatter(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        if (!str_starts_with($markdown, "---\n")) {
            return ['', $markdown];
        }

        $end = strpos($markdown, "\n---\n", 4);
        if ($end === false) {
            return ['', $markdown];
        }

        return [
            substr($markdown, 4, $end - 4),
            substr($markdown, $end + 5),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseFrontMatter(string $frontMatter): array
    {
        $out = [];
        foreach (explode("\n", $frontMatter) as $line) {
            if (!preg_match('/^([A-Za-z0-9_-]+):\s*(.+)$/', trim($line), $matches)) {
                continue;
            }
            $out[$matches[1]] = trim($matches[2], " \t\n\r\0\x0B\"'");
        }
        return $out;
    }

    private function firstParagraph(string $markdown): string
    {
        $paragraph = [];
        foreach (explode("\n", $markdown) as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($paragraph !== []) {
                    break;
                }
                continue;
            }
            if ($paragraph === [] && preg_match('/^(#{1,6}\s|```|~~~|\||!\[)/', $line)) {
                continue;
            }
            $paragraph[] = $line;
        }

        return $this->plainText(implode(' ', $paragraph));
    }

    private function plainText(string $markdown): string
    {
        $text = preg_replace('/!\[([^\]]*)\]\([^)]+\)/', '$1', $markdown) ?? $markdown;
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text) ?? $text;
        $text = preg_replace('/[`*_>#-]+/', ' ', $text) ?? $text;
        $text = strip_tags($text);
        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function tagsFromFrontMatter(mixed $value): array
    {
        $tags = trim((string) $value);
        if ($tags === '') {
            return [];
        }
        $tags = trim($tags, '[]');
        return array_values(array_filter(array_map(
            fn (string $tag): string => trim($tag, " \t\n\r\0\x0B\"'"),
            explode(',', $tags)
        )));
    }

    private function slugFromPath(string $relativePath): string
    {
        $slug = preg_replace('/\.(?:md|markdown)$/i', '', trim($relativePath, '/')) ?? '';
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9\/._-]+/', '-', $slug) ?? $slug;
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        return trim($slug, '-/');
    }

    private function titleFromPath(string $relativePath): string
    {
        $name = preg_replace('/\.(?:md|markdown)$/i', '', basename($relativePath)) ?? basename($relativePath);
        $name = str_replace(['-', '_'], ' ', $name);
        return ucwords(trim($name));
    }

    private function categoryFromPath(string $relativePath): string
    {
        $dir = trim(dirname($relativePath), '. /');
        return $dir === '' ? '' : $dir;
    }

    private function relativePath(string $path, string $docsPath): string
    {
        $path = trim($path, '/');
        $prefix = trim($docsPath, '/');
        if ($prefix !== '' && str_starts_with($path, $prefix . '/')) {
            return substr($path, strlen($prefix) + 1);
        }
        return $path;
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

    private function isMarkdownPath(string $path): bool
    {
        return (bool) preg_match('/\.(?:md|markdown)$/i', $path);
    }

    /**
     * @param array{owner:string,repo:string,branch:string,docs_path:string} $config
     */
    private function htmlUrl(array $config, string $path): string
    {
        return 'https://github.com/' . rawurlencode($config['owner'])
            . '/' . rawurlencode($config['repo'])
            . '/blob/' . rawurlencode($config['branch'])
            . '/' . str_replace('%2F', '/', rawurlencode($path));
    }

    /**
     * @param array{owner:string,repo:string,branch:string,docs_path:string} $config
     * @param array<string, mixed> $credentials
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function requestJson(array $config, array $credentials, string $path, array $query = []): array
    {
        $url = 'https://api.github.com/repos/' . rawurlencode($config['owner'])
            . '/' . rawurlencode($config['repo'])
            . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: TypeDock/0.1 ExternalSource',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        $token = trim((string) ($credentials['delivery_token'] ?? ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $body = $this->httpGet($url, $headers);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('GitHub returned invalid JSON.');
        }

        return $json;
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
                throw new \RuntimeException($error !== '' ? $error : 'GitHub request failed.');
            }
            if ($status < 200 || $status >= 300) {
                throw new GitHubRequestException($status, (string) $body);
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
                throw new GitHubRequestException($status);
            }
            throw new \RuntimeException('GitHub request failed.');
        }
        if ($status >= 400) {
            throw new GitHubRequestException($status, (string) $body);
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
