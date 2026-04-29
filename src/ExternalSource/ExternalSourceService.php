<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

use Ramsey\Uuid\Uuid;
use TypeDock\Content\SlugValidator;
use TypeDock\Exception\NotFoundException;
use TypeDock\Exception\ValidationException;

final class ExternalSourceService
{
    private readonly ExternalSourceAdapterRegistry $adapterRegistry;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly SourceCredentialCipher $cipher = new SourceCredentialCipher(),
        ?ExternalSourceAdapterRegistry $adapterRegistry = null,
    ) {
        $this->adapterRegistry = $adapterRegistry ?? ExternalSourceAdapterRegistry::withBuiltIns();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM external_sources ORDER BY name ASC');
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt !== false ? $stmt->fetchAll() : []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM external_sources WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM external_sources WHERE slug = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeSources(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM external_sources WHERE status = 'active' ORDER BY slug ASC");
        return array_map(fn (array $row): array => $this->hydrate($row), $stmt !== false ? $stmt->fetchAll() : []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableAdapters(): array
    {
        return array_values(array_map(
            fn (ExternalSourceAdapterInterface $adapter): array => $adapter->metadata()->toArray(),
            $this->adapterRegistry->all()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function blankSource(string $provider = 'contentful'): array
    {
        $metadata = $this->adapterByProvider($provider)->metadata();
        return [
            'id' => '',
            'slug' => '',
            'name' => '',
            'provider' => $metadata->id,
            'status' => 'active',
            'config' => $metadata->defaultConfig,
            'field_mapping' => $metadata->defaultMapping,
            'detail_template' => $metadata->defaultDetailTemplate,
            'cache_ttl_seconds' => 600,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $id = Uuid::uuid7()->toString();
        $now = $this->now();
        $row = $this->normalizeInput($data);
        $this->validate($row);

        $stmt = $this->pdo->prepare(
            'INSERT INTO external_sources (id, slug, name, provider, status, config, field_mapping, detail_template, cache_ttl_seconds, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );

        $secrets = $this->normalizeSecrets($data);
        $metadata = $this->adapter($row)->metadata();
        if ($metadata->tokenRequired && ($secrets['delivery_token'] ?? '') === '') {
            throw new ValidationException(['delivery_token' => [$metadata->tokenLabel . ' is required.']]);
        }
        $this->validateProviderCredentialFields($row, $secrets);
        $this->validateConnection($row, $secrets);

        $this->pdo->beginTransaction();
        try {
            $stmt->execute([
                $id,
                $row['slug'],
                $row['name'],
                $row['provider'],
                $row['status'],
                json_encode($row['config'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($row['field_mapping'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $row['detail_template'],
                $row['cache_ttl_seconds'],
                $now,
                $now,
            ]);
            if ($this->shouldStoreCredentials($row, $secrets)) {
                $this->saveCredentials($id, $secrets, $now);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->find($id) ?? throw new NotFoundException('External Source was not created.');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(string $id, array $data): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new NotFoundException('External Source not found.');
        }

        $row = $this->normalizeInput($data, $existing);
        $this->validate($row, $id);
        $now = $this->now();
        $secrets = $this->normalizeSecrets($data);
        $hasNewDeliveryToken = ($secrets['delivery_token'] ?? '') !== '';

        if ($hasNewDeliveryToken) {
            $this->validateProviderCredentialFields($row, $secrets);
            $this->validateConnection($row, $secrets);
        } elseif ($this->connectionChanged($existing, $row)) {
            $credentials = $this->credentials($id);
            $this->validateProviderCredentialFields($row, $credentials);
            $this->validateConnection($row, $credentials);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE external_sources
             SET slug = ?, name = ?, provider = ?, status = ?, config = ?, field_mapping = ?, detail_template = ?, cache_ttl_seconds = ?, updated_at = ?
             WHERE id = ?'
        );

        $this->pdo->beginTransaction();
        try {
            $stmt->execute([
                $row['slug'],
                $row['name'],
                $row['provider'],
                $row['status'],
                json_encode($row['config'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                json_encode($row['field_mapping'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $row['detail_template'],
                $row['cache_ttl_seconds'],
                $now,
                $id,
            ]);

            if ($hasNewDeliveryToken) {
                $this->saveCredentials($id, $secrets, $now);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->clearCache($id);

        return $this->find($id) ?? throw new NotFoundException('External Source not found after update.');
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM external_sources WHERE id = ?')->execute([$id]);
        $this->clearCache($id);
    }

    public function clearCache(string $id): void
    {
        $dir = $this->cacheDir($id);
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * @param array<string, mixed> $source
     * @return array{items:array<int,object>,total:int,stale:bool,error:?string}
     */
    public function fetchList(array $source, int $limit = 10, int $page = 1): array
    {
        $offset = (max(1, $page) - 1) * $limit;
        $cacheKey = 'list-' . $limit . '-' . $offset;

        $result = $this->withCache($source, $cacheKey, function () use ($source, $limit, $offset): array {
            $adapter = $this->adapter($source);
            $credentials = $this->credentials((string) $source['id']);
            $result = $adapter->list($source, $credentials, $limit, $offset);
            return [
                'items' => array_map(fn (array $item): object => $this->projectItem($source, $item), $result['items']),
                'total' => $result['total'],
            ];
        });

        $result['items'] = array_map(
            fn (mixed $item): object => is_object($item) ? $item : (object) (is_array($item) ? $item : []),
            is_array($result['items'] ?? null) ? $result['items'] : []
        );
        $result['total'] = (int) ($result['total'] ?? count($result['items']));
        return $result;
    }

    /**
     * @param array<string, mixed> $source
     * @return array{item:?object,stale:bool,error:?string}
     */
    public function fetchItem(array $source, string $slug): array
    {
        $cacheKey = 'item-' . sha1($slug);

        $result = $this->withCache($source, $cacheKey, function () use ($source, $slug): array {
            $adapter = $this->adapter($source);
            $credentials = $this->credentials((string) $source['id']);
            $item = $adapter->getBySlug($source, $credentials, $slug);
            return [
                'item' => $item !== null ? $this->projectItem($source, $item) : null,
            ];
        });

        $item = $result['item'] ?? null;
        if (is_array($item)) {
            $item = (object) $item;
        }

        return [
            'item' => $item instanceof \stdClass ? $item : null,
            'stale' => (bool) ($result['stale'] ?? false),
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function rawCredentialsForDiagnostics(array $source): array
    {
        try {
            $credentials = $this->credentials((string) ($source['id'] ?? ''));
        } catch (\Throwable) {
            $credentials = [];
        }
        return [
            'has_delivery_token' => trim((string) ($credentials['delivery_token'] ?? '')) !== '',
            'rotation_note' => 'APP_KEY rotation requires re-entering External Source credentials.',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    public function discoverFields(string $id, array $data = []): array
    {
        $existing = $this->find($id);
        if ($existing === null) {
            throw new NotFoundException('External Source not found.');
        }

        $source = $this->normalizeInput($data, $existing);
        $this->validate($source, $id);
        $credentials = $this->credentials($id);
        $secrets = $this->normalizeSecrets($data);
        if (($secrets['delivery_token'] ?? '') !== '') {
            $credentials = $secrets;
        }

        try {
            $fields = $this->adapter($source)->discoverFields($source, $credentials);
        } catch (\Throwable $e) {
            throw $this->connectionValidationException($e);
        }

        if ($fields === []) {
            throw new ValidationException(['connection' => [$this->providerLabel($source) . ' returned no fields for mapping.']]);
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>      $source
     * @param callable():array<string,mixed> $loader
     * @return array<string, mixed>
     */
    private function withCache(array $source, string $key, callable $loader): array
    {
        $file = $this->cacheDir((string) $source['id']) . '/' . preg_replace('/[^A-Za-z0-9_.-]/', '-', $key) . '.json';
        $ttl = max(0, (int) ($source['cache_ttl_seconds'] ?? 600));
        $cached = $this->readCache($file);

        if ($cached !== null && $ttl > 0 && time() - (int) ($cached['stored_at'] ?? 0) <= $ttl) {
            return array_merge((array) ($cached['data'] ?? []), ['stale' => false, 'error' => null]);
        }

        try {
            $data = $loader();
            $this->writeCache($file, $data);
            return array_merge($data, ['stale' => false, 'error' => null]);
        } catch (\Throwable $e) {
            if ($cached !== null) {
                return array_merge((array) ($cached['data'] ?? []), ['stale' => true, 'error' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readCache(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeCache(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($file, json_encode([
            'stored_at' => time(),
            'data' => $data,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function cacheDir(string $id): string
    {
        return storage_path('cache/external-sources/' . preg_replace('/[^A-Za-z0-9_-]/', '-', $id));
    }

    private function adapterByProvider(string $provider): ExternalSourceAdapterInterface
    {
        return $this->adapterRegistry->get($provider) ?? $this->adapterRegistry->first();
    }

    /**
     * @param array<string, mixed> $source
     */
    private function adapter(array $source): ExternalSourceAdapterInterface
    {
        $provider = (string) ($source['provider'] ?? 'contentful');
        return $this->adapterRegistry->require($provider);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     */
    private function validateConnection(array $source, array $credentials): void
    {
        try {
            $adapter = $this->adapter($source);
            $adapter->discoverFields($source, $credentials);
            $adapter->list($source, $credentials, 1, 0);
        } catch (\Throwable $e) {
            throw $this->connectionValidationException($e);
        }
    }

    private function connectionValidationException(\Throwable $e): ValidationException
    {
        $message = 'Could not connect to the external source. Check the connection settings.';

        if (str_ends_with($e::class, '\\ContentfulRequestException')) {
            $statusCode = $this->httpStatusFromException($e);
            $message = match ($statusCode) {
                401, 403 => 'Contentful rejected the token. Use a Content Delivery API token for this Space.',
                404 => 'Contentful could not find the Space, Environment, or Content type. Check the API identifiers, not display names.',
                429 => 'Contentful rate limited this request. Wait a moment and try again.',
                default => 'Contentful returned HTTP ' . $statusCode . '. Check the connection settings.',
            };
            $details = method_exists($e, 'contentfulMessage') ? (string) $e->contentfulMessage() : '';
            if ($details !== '') {
                $message .= ' ' . $details;
            }
        } elseif (str_contains($e->getMessage(), 'delivery token is missing')) {
            $message = 'Contentful delivery token is required.';
        } elseif (str_ends_with($e::class, '\\GitHubRequestException')) {
            $statusCode = $this->httpStatusFromException($e);
            $message = match ($statusCode) {
                401, 403 => 'GitHub rejected the request. Check the token, repository permissions, or API rate limit.',
                404 => 'GitHub could not find the repository. Check owner, repository name, and private repository access.',
                422 => 'GitHub rejected the query parameters. Check state and labels.',
                429 => 'GitHub rate limited this request. Wait a moment and try again.',
                default => 'GitHub returned HTTP ' . $statusCode . '. Check the connection settings.',
            };
            $details = method_exists($e, 'githubMessage') ? (string) $e->githubMessage() : '';
            if ($details !== '') {
                $message .= ' ' . $details;
            }
        } elseif (str_contains($e->getMessage(), 'WordPress Basic auth requires')) {
            $message = 'WordPress Basic auth requires both a username and an application password.';
        } elseif (str_contains($e->getMessage(), 'WordPress Bearer auth requires')) {
            $message = 'WordPress Bearer auth requires an API token.';
        } elseif ($e instanceof WordPressRequestException) {
            $message = match ($e->statusCode()) {
                401, 403 => 'WordPress rejected the request. Check the auth mode, application password, or REST API permissions.',
                404 => 'WordPress could not find the REST endpoint. Check the site URL, REST base path, and resource type.',
                400 => 'WordPress rejected the query parameters. Check status, resource type, and locale settings.',
                429 => 'WordPress rate limited this request. Wait a moment and try again.',
                default => 'WordPress returned HTTP ' . $e->statusCode() . '. Check the connection settings.',
            };
            $details = $e->wordPressMessage();
            if ($details !== '') {
                $message .= ' ' . $details;
            }
        } elseif (str_contains($e->getMessage(), 'Generic JSON Bearer auth requires')) {
            $message = 'Generic JSON Bearer auth requires an API token.';
        } elseif (str_contains($e->getMessage(), 'Generic JSON Basic auth requires')) {
            $message = 'Generic JSON Basic auth requires both a username and an API token.';
        } elseif ($e instanceof GenericJsonRequestException) {
            $message = match ($e->statusCode()) {
                401, 403 => 'Generic JSON endpoint rejected the request. Check the token and endpoint permissions.',
                404 => 'Generic JSON endpoint was not found. Check the list or detail endpoint URL.',
                429 => 'Generic JSON endpoint rate limited this request. Wait a moment and try again.',
                default => 'Generic JSON endpoint returned HTTP ' . $e->statusCode() . '. Check the connection settings.',
            };
            $details = $e->jsonMessage();
            if ($details !== '') {
                $message .= ' ' . $details;
            }
        }

        return new ValidationException(
            ['connection' => [$message]],
            'External Source connection failed: ' . $message,
            422,
            $e
        );
    }

    private function httpStatusFromException(\Throwable $e): int
    {
        return method_exists($e, 'statusCode') ? (int) $e->statusCode() : (int) $e->getCode();
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $credentials
     */
    private function validateProviderCredentialFields(array $source, array $credentials): void
    {
        $provider = (string) ($source['provider'] ?? '');
        $config = is_array($source['config'] ?? null) ? $source['config'] : [];
        $token = preg_replace('/\s+/', '', (string) ($credentials['delivery_token'] ?? '')) ?? '';
        $errors = [];

        if ($provider === 'wordpress_rest') {
            $authMode = (string) ($config['wp_auth_mode'] ?? 'none');
            if ($authMode === 'basic') {
                if (trim((string) ($config['wp_username'] ?? '')) === '') {
                    $errors['wp_username'][] = 'Username is required for WordPress Basic auth.';
                }
                if ($token === '') {
                    $errors['delivery_token'][] = 'Application password is required for WordPress Basic auth.';
                }
            } elseif ($authMode === 'bearer' && $token === '') {
                $errors['delivery_token'][] = 'Bearer token is required for WordPress Bearer auth.';
            }
        } elseif ($provider === 'generic_json') {
            $authMode = (string) ($config['json_auth_mode'] ?? 'none');
            if ($authMode === 'basic') {
                if (trim((string) ($config['json_basic_username'] ?? '')) === '') {
                    $errors['json_basic_username'][] = 'Username is required for Generic JSON Basic auth.';
                }
                if ($token === '') {
                    $errors['delivery_token'][] = 'API token is required for Generic JSON Basic auth.';
                }
            } elseif ($authMode === 'bearer' && $token === '') {
                $errors['delivery_token'][] = 'API token is required for Generic JSON Bearer auth.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $next
     */
    private function connectionChanged(array $existing, array $next): bool
    {
        return ($existing['provider'] ?? null) !== ($next['provider'] ?? null)
            || ($existing['config'] ?? []) !== ($next['config'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(string $sourceId): array
    {
        $stmt = $this->pdo->prepare('SELECT payload FROM external_source_credentials WHERE source_id = ? LIMIT 1');
        $stmt->execute([$sourceId]);
        $payload = $stmt->fetchColumn();
        return $this->cipher->decrypt(is_string($payload) ? $payload : null);
    }

    /**
     * @param array<string, mixed> $secrets
     */
    private function saveCredentials(string $sourceId, array $secrets, string $now): void
    {
        $payload = $this->cipher->encrypt($secrets);
        $exists = $this->pdo->prepare('SELECT source_id FROM external_source_credentials WHERE source_id = ? LIMIT 1');
        $exists->execute([$sourceId]);
        if ($exists->fetch() !== false) {
            $stmt = $this->pdo->prepare('UPDATE external_source_credentials SET payload = ?, updated_at = ? WHERE source_id = ?');
            $stmt->execute([$payload, $now, $sourceId]);
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO external_source_credentials (source_id, payload, created_at, updated_at) VALUES (?, ?, ?, ?)');
        $stmt->execute([$sourceId, $payload, $now, $now]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $metadata = $this->adapterByProvider((string) ($row['provider'] ?? 'contentful'))->metadata();
        $row['provider'] = $metadata->id;
        $row['config'] = $this->decodeObject($row['config'] ?? null) + $metadata->defaultConfig;
        $row['field_mapping'] = $this->decodeObject($row['field_mapping'] ?? null) + $metadata->defaultMapping;
        $row['cache_ttl_seconds'] = (int) ($row['cache_ttl_seconds'] ?? 600);
        return $row;
    }

    /**
     * @return array<string, string>
     */
    private function defaultMapping(string $provider = 'contentful'): array
    {
        return $this->adapterByProvider($provider)->metadata()->defaultMapping;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function normalizeInput(array $data, ?array $existing = null): array
    {
        $provider = $this->adapterByProvider((string) ($data['provider'] ?? $existing['provider'] ?? 'contentful'))->metadata()->id;

        $defaults = $this->defaultMapping($provider);
        $mapping = [];
        foreach (array_keys($defaults) as $key) {
            $mapping[$key] = trim((string) ($data['field_' . $key] ?? $existing['field_mapping'][$key] ?? $defaults[$key]));
        }

        return [
            'slug' => $this->normalizeSlug((string) ($data['slug'] ?? $existing['slug'] ?? '')),
            'name' => trim((string) ($data['name'] ?? $existing['name'] ?? '')),
            'provider' => $provider,
            'status' => in_array(($data['status'] ?? $existing['status'] ?? 'active'), ['active', 'draft'], true)
                ? (string) ($data['status'] ?? $existing['status'] ?? 'active')
                : 'draft',
            'config' => $this->normalizeConfig($provider, $data, $existing),
            'field_mapping' => $mapping,
            'detail_template' => trim((string) ($data['detail_template'] ?? $existing['detail_template'] ?? $this->defaultDetailTemplate($provider))),
            'cache_ttl_seconds' => max(0, min(86400, (int) ($data['cache_ttl_seconds'] ?? $existing['cache_ttl_seconds'] ?? 600))),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array<string, string>
     */
    private function normalizeConfig(string $provider, array $data, ?array $existing): array
    {
        $existingConfig = is_array($existing['config'] ?? null) ? $existing['config'] : [];
        $metadata = $this->adapterByProvider($provider)->metadata();
        $config = [];
        foreach ($metadata->configFields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $value = trim((string) ($data[$name] ?? $existingConfig[$name] ?? $metadata->defaultConfig[$name] ?? ''));
            if (($field['type'] ?? '') === 'select') {
                $allowed = array_map(
                    fn (array $option): string => (string) ($option['value'] ?? ''),
                    is_array($field['options'] ?? null) ? $field['options'] : []
                );
                if ($allowed !== [] && !in_array($value, $allowed, true)) {
                    $value = (string) ($metadata->defaultConfig[$name] ?? $allowed[0]);
                }
            }
            $config[$name] = $value;
        }
        return $config + $metadata->defaultConfig;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function normalizeSecrets(array $data): array
    {
        return [
            'delivery_token' => trim((string) ($data['delivery_token'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function validate(array $row, ?string $excludeId = null): void
    {
        $errors = [];
        if ($row['name'] === '') {
            $errors['name'][] = 'Name is required.';
        }
        $metadata = $this->adapterByProvider((string) ($row['provider'] ?? 'contentful'))->metadata();
        foreach ($metadata->configFields as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '' && !empty($field['required']) && trim((string) ($row['config'][$name] ?? '')) === '') {
                $errors[$name][] = $field['label'] . ' is required.';
            }
        }

        try {
            (new SlugValidator())->validate((string) $row['slug']);
            $this->ensurePrefixAvailable((string) $row['slug'], $excludeId);
        } catch (ValidationException $e) {
            $errors['slug'] = array_merge($errors['slug'] ?? [], $this->flattenErrors($e));
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * @return array<int, string>
     */
    private function flattenErrors(ValidationException $e): array
    {
        $messages = [];
        foreach ($e->getErrors() as $list) {
            foreach ($list as $message) {
                $messages[] = $message;
            }
        }
        return $messages;
    }

    private function ensurePrefixAvailable(string $slug, ?string $excludeId): void
    {
        $dynamicReserved = array_unique([
            posts_archive_slug(), 'admin', 'api', 'category', 'tag', 'author', 'search', 'page',
            'feed', 'sitemap.xml', 'robots.txt',
        ]);
        $top = explode('/', $slug)[0];
        if (in_array($top, $dynamicReserved, true)) {
            throw new ValidationException(['slug' => ['This prefix conflicts with a reserved route.']]);
        }

        $stmt = $this->pdo->prepare("SELECT slug FROM posts WHERE post_type = 'page' AND status != 'trash'");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $pageSlug = trim((string) ($row['slug'] ?? ''), '/');
            if ($pageSlug !== '' && $this->prefixesOverlap($slug, $pageSlug)) {
                throw new ValidationException(['slug' => ['This prefix conflicts with an existing page URL.']]);
            }
        }

        $sql = 'SELECT id, slug FROM external_sources';
        $args = [];
        if ($excludeId !== null) {
            $sql .= ' WHERE id != ?';
            $args[] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $row) {
            $other = trim((string) ($row['slug'] ?? ''), '/');
            if ($other !== '' && $this->prefixesOverlap($slug, $other)) {
                throw new ValidationException(['slug' => ['This prefix overlaps another External Source.']]);
            }
        }
    }

    private function prefixesOverlap(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a . '/', $b . '/') || str_starts_with($b . '/', $a . '/');
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug, "/ \t\n\r\0\x0B"));
        $slug = preg_replace('#/+#', '/', $slug) ?? '';
        return $slug;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function decodeObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>
     */
    private function defaultConfig(string $provider): array
    {
        return $this->adapterByProvider($provider)->metadata()->defaultConfig;
    }

    private function defaultDetailTemplate(string $provider = 'contentful'): string
    {
        return $this->adapterByProvider($provider)->metadata()->defaultDetailTemplate;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $item
     */
    private function projectItem(array $source, array $item): object
    {
        $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
        $sys = is_array($item['sys'] ?? null) ? $item['sys'] : [];
        $mapping = is_array($source['field_mapping'] ?? null) ? $source['field_mapping'] : $this->defaultMapping();

        $slug = $this->stringValue($this->mapped($item, $mapping['slug'] ?? 'slug')) ?: (string) ($sys['id'] ?? '');
        $title = $this->stringValue($this->mapped($item, $mapping['title'] ?? 'title')) ?: 'Untitled';
        $excerpt = $this->stringValue($this->mapped($item, $mapping['excerpt'] ?? 'excerpt'));
        $date = $this->stringValue($this->mapped($item, $mapping['date'] ?? 'publishedAt')) ?: (string) ($sys['updatedAt'] ?? '');
        $tags = $this->listValue($this->mapped($item, $mapping['tags'] ?? 'tags'));
        $content = $this->mapped($item, $mapping['content'] ?? 'content');

        return (object) [
            'id' => (string) ($sys['id'] ?? sha1(json_encode($item))),
            'slug' => $slug,
            'url' => '/' . trim((string) $source['slug'], '/') . '/' . rawurlencode($slug),
            'title' => $title,
            'excerpt' => $excerpt,
            'thumbnail' => $this->stringValue($this->mapped($item, $mapping['thumbnail'] ?? 'thumbnail')),
            'thumbnailAlt' => $title,
            'date' => $date,
            'publishedAt' => $date,
            'category' => $this->stringValue($this->mapped($item, $mapping['category'] ?? 'category')),
            'tags' => $tags,
            'content' => $content,
            'contentHtml' => (new StructuredRichTextRenderer())->render($content),
            'fields' => $fields,
            'sys' => $sys,
            'raw' => $item,
        ];
    }

    private function mapped(array $item, string $path): mixed
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        $direct = $this->path($item, $path);
        if ($direct !== null) {
            return $direct;
        }
        if (isset($item['fields']) && is_array($item['fields'])) {
            return $this->path($item['fields'], $path);
        }
        return null;
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
            if (is_object($value) && isset($value->{$part})) {
                $value = $value->{$part};
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
        if (is_array($value) && isset($value['url']) && is_scalar($value['url'])) {
            return trim((string) $value['url']);
        }
        if (is_array($value)) {
            foreach (['title', 'name', 'label', 'description'] as $key) {
                if (isset($value[$key]) && is_scalar($value[$key])) {
                    return trim((string) $value[$key]);
                }
            }
            $fields = is_array($value['fields'] ?? null) ? $value['fields'] : [];
            foreach (['title', 'name', 'label', 'description'] as $key) {
                if (isset($fields[$key]) && is_scalar($fields[$key])) {
                    return trim((string) $fields[$key]);
                }
            }
        }
        return '';
    }

    /**
     * @return array<int, string>
     */
    private function listValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(fn (mixed $v): string => $this->stringValue($v), $value)));
        }
        return array_values(array_filter(array_map('trim', explode(',', $this->stringValue($value)))));
    }

    /**
     * @param array<string, mixed> $source
     */
    private function requiresToken(array $source): bool
    {
        return $this->adapterByProvider((string) ($source['provider'] ?? 'contentful'))->metadata()->tokenRequired;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $secrets
     */
    private function shouldStoreCredentials(array $source, array $secrets): bool
    {
        return $this->requiresToken($source) || trim((string) ($secrets['delivery_token'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function providerLabel(array $source): string
    {
        return $this->adapterByProvider((string) ($source['provider'] ?? 'contentful'))->metadata()->label;
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
