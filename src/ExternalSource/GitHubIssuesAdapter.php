<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

final class GitHubIssuesAdapter implements ExternalSourceAdapterInterface
{
    public function metadata(): ExternalSourceAdapterMetadata
    {
        return new ExternalSourceAdapterMetadata(
            id: 'github_issues',
            label: 'GitHub Issues',
            description: 'Read-only issues from a GitHub repository',
            tokenRequired: false,
            tokenLabel: 'GitHub API token',
            tokenHelp: 'Optional for public repositories. Use a fine-grained token for private repositories or higher rate limits.',
            configFields: [
                ['name' => 'github_owner', 'label' => 'Owner', 'type' => 'text', 'required' => true, 'placeholder' => 'typedock'],
                ['name' => 'github_repo', 'label' => 'Repository', 'type' => 'text', 'required' => true, 'placeholder' => 'typedock'],
                [
                    'name' => 'github_state',
                    'label' => 'Issue state',
                    'type' => 'select',
                    'required' => false,
                    'options' => [
                        ['value' => 'open', 'label' => 'Open'],
                        ['value' => 'closed', 'label' => 'Closed'],
                        ['value' => 'all', 'label' => 'All'],
                    ],
                ],
                ['name' => 'github_labels', 'label' => 'Labels', 'type' => 'text', 'required' => false, 'placeholder' => 'documentation,good first issue', 'hint' => 'Comma-separated GitHub labels. Leave blank for all issues in the selected state.'],
            ],
            defaultConfig: [
                'github_owner' => '',
                'github_repo' => '',
                'github_state' => 'open',
                'github_labels' => '',
            ],
            defaultMapping: [
                'slug' => 'slug',
                'title' => 'title',
                'excerpt' => 'body',
                'thumbnail' => '',
                'date' => 'created_at',
                'category' => 'state',
                'tags' => 'labels',
                'content' => 'body',
            ],
            defaultDetailTemplate: "[resource.content|markdown]\n\nState: [resource.category]\nLabels: [resource.tags|join:\", \"]\nGitHub: [resource.raw.fields.html_url|url]",
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
            'state' => $config['state'],
            'per_page' => $perPage,
            'page' => $page,
            'sort' => 'updated',
            'direction' => 'desc',
        ];
        if ($config['labels'] !== '') {
            $query['labels'] = $config['labels'];
        }

        $json = $this->request($config, $credentials, 'issues', $query);
        $items = is_array($json) ? $json : [];
        $issues = [];
        foreach ($items as $issue) {
            if (!is_array($issue) || isset($issue['pull_request'])) {
                continue;
            }
            $issues[] = $this->normalizeIssue($issue);
        }

        return [
            'items' => $issues,
            'total' => $offset + count($issues) + (count($issues) === $perPage ? 1 : 0),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     * @return array<string, mixed>|null
     */
    public function getBySlug(array $source, array $credentials, string $slug): ?array
    {
        if (!preg_match('/^issue-([1-9][0-9]*)$/', $slug, $matches)) {
            return null;
        }

        $config = $this->config($source);
        $issue = $this->request($config, $credentials, 'issues/' . rawurlencode($matches[1]));
        if (!is_array($issue) || isset($issue['pull_request'])) {
            return null;
        }

        return $this->normalizeIssue($issue);
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
            $this->field('number', 'Issue number', 'Integer', ''),
            $this->field('title', 'Title', 'String', 'title'),
            $this->field('body', 'Body', 'Markdown', 'content'),
            $this->field('state', 'State', 'String', 'category'),
            $this->field('labels', 'Labels', 'Array', 'tags'),
            $this->field('created_at', 'Created at', 'Date', 'date'),
            $this->field('updated_at', 'Updated at', 'Date', ''),
            $this->field('closed_at', 'Closed at', 'Date', ''),
            $this->field('html_url', 'GitHub URL', 'URL', ''),
            $this->field('user.login', 'Author login', 'String', ''),
            $this->field('milestone.title', 'Milestone title', 'String', ''),
            $this->field('comments', 'Comment count', 'Integer', ''),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{owner:string,repo:string,state:string,labels:string}
     */
    private function config(array $source): array
    {
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $owner = trim((string) ($config['github_owner'] ?? ''));
        $repo = trim((string) ($config['github_repo'] ?? ''));
        if ($owner === '' || $repo === '') {
            throw new \RuntimeException('GitHub Issues source requires owner and repository.');
        }

        $state = trim((string) ($config['github_state'] ?? 'open')) ?: 'open';
        if (!in_array($state, ['open', 'closed', 'all'], true)) {
            $state = 'open';
        }

        return [
            'owner' => $owner,
            'repo' => $repo,
            'state' => $state,
            'labels' => trim((string) ($config['github_labels'] ?? '')),
        ];
    }

    /**
     * @param array{owner:string,repo:string,state:string,labels:string} $config
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $query
     * @return mixed
     */
    private function request(array $config, array $credentials, string $path, array $query = []): mixed
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
     * @param array<string, mixed> $issue
     * @return array<string, mixed>
     */
    private function normalizeIssue(array $issue): array
    {
        return [
            'sys' => [
                'id' => (string) ($issue['id'] ?? $issue['number'] ?? ''),
                'updatedAt' => (string) ($issue['updated_at'] ?? ''),
            ],
            'fields' => ['slug' => 'issue-' . (string) ($issue['number'] ?? '')] + $issue,
        ];
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
            'required' => in_array($id, ['number', 'title'], true),
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
