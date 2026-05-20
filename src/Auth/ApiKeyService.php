<?php
declare(strict_types=1);

namespace TypeDock\Auth;

class ApiKeyService
{
    /** Key prefix length */
    private const PREFIX_LENGTH = 8;
    /** Random part length */
    private const RANDOM_LENGTH = 32;

    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Create new API key. Returns ['key' => plaintext, 'id' => uuid].
     * The plaintext key is shown only once.
     *
     * @param array<string>|null $permissions
     * @return array{key: string, id: string}
     */
    public function create(
        string $userId,
        string $name,
        ?array $permissions = null,
        ?\DateTimeInterface $expiresAt = null
    ): array {
        $prefix    = bin2hex(random_bytes(self::PREFIX_LENGTH / 2)); // 8 hex chars
        $random    = bin2hex(random_bytes(self::RANDOM_LENGTH / 2)); // 32 hex chars
        $plaintext = 'td_' . $prefix . '_' . $random;
        $keyHash   = hash('sha256', $plaintext);
        $id        = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_keys (id, user_id, name, key_hash, key_prefix, permissions, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $name,
            $keyHash,
            $prefix,
            $permissions !== null ? json_encode($permissions) : null,
            $expiresAt?->format('Y-m-d H:i:s'),
            $now,
        ]);

        return ['key' => $plaintext, 'id' => $id];
    }

    /**
     * Validate a Bearer token. Returns user+permissions array or null.
     *
     * @return array{id: string, user_id: string, email: string|null, name: string|null, role: string, permissions: array<string>|null, api_key_id: string}|null
     */
    public function validate(string $bearerToken, ?string $requiredPermission = null): ?array
    {
        // Parse: td_{prefix}_{random}
        if (!str_starts_with($bearerToken, 'td_')) {
            return null;
        }

        $parts = explode('_', $bearerToken, 3);
        if (count($parts) < 3) {
            return null;
        }

        $prefix = $parts[1];
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT ak.id, ak.user_id, ak.key_hash, ak.permissions, ak.expires_at,
                    u.email, u.name, u.role
             FROM api_keys ak
             JOIN users u ON u.id = ak.user_id
             WHERE ak.key_prefix = ? AND (ak.expires_at IS NULL OR ak.expires_at > ?)
             LIMIT 5'
        );
        $stmt->execute([$prefix, $now]);
        $candidates = $stmt->fetchAll();

        $keyHash  = hash('sha256', $bearerToken);
        $matched  = null;
        foreach ($candidates as $candidate) {
            if (hash_equals((string) $candidate['key_hash'], $keyHash)) {
                $matched = $candidate;
                break;
            }
        }

        if ($matched === null) {
            return null;
        }

        // Update last_used_at
        $this->pdo->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')
            ->execute([$now, $matched['id']]);

        $decodedPermissions = $matched['permissions'] !== null
            ? json_decode((string) $matched['permissions'], true)
            : null;
        $permissions = is_array($decodedPermissions) ? array_values(array_filter(
            $decodedPermissions,
            static fn(mixed $permission): bool => is_string($permission) && $permission !== ''
        )) : null;

        if ($requiredPermission !== null) {
            if (is_array($permissions)) {
                if (!in_array($requiredPermission, $permissions, true)) {
                    return null;
                }
            } else {
                $user = [
                    'id'    => (string) $matched['user_id'],
                    'email' => $matched['email'] !== null ? (string) $matched['email'] : null,
                    'name'  => $matched['name'] !== null ? (string) $matched['name'] : null,
                    'role'  => (string) ($matched['role'] ?? 'contributor'),
                ];
                if (!(new PermissionChecker())->can($user, $requiredPermission)) {
                    return null;
                }
            }
        }

        return [
            'id'          => (string) $matched['user_id'],
            'user_id'     => (string) $matched['user_id'],
            'email'       => $matched['email'] !== null ? (string) $matched['email'] : null,
            'name'        => $matched['name'] !== null ? (string) $matched['name'] : null,
            'role'        => (string) ($matched['role'] ?? 'contributor'),
            'permissions' => $permissions,
            'api_key_id'  => (string) $matched['id'],
        ];
    }

    /**
     * Check a permission against a validated API key payload.
     *
     * @param array<string, mixed> $apiUser
     */
    public function can(array $apiUser, string $permission): bool
    {
        $permissions = $apiUser['permissions'] ?? null;
        if (is_array($permissions)) {
            return in_array($permission, $permissions, true);
        }

        return (new PermissionChecker())->can($apiUser, $permission);
    }

    /**
     * @return array<string, string>
     */
    public static function availableScopes(): array
    {
        return [
            'posts:read'       => 'Read posts',
            'posts:create'     => 'Create posts',
            'posts:publish'    => 'Publish posts',
            'posts:edit_own'   => 'Edit own posts',
            'posts:edit_any'   => 'Edit any post',
            'posts:delete_own' => 'Delete own posts',
            'posts:delete_any' => 'Delete any post',
            'pages:read'       => 'Read pages',
            'pages:create'     => 'Create pages',
            'pages:publish'    => 'Publish pages',
            'pages:edit_own'   => 'Edit own pages',
            'pages:edit_any'   => 'Edit any page',
            'pages:delete_any' => 'Delete any page',
            'media:read'       => 'Read media',
            'media:upload'     => 'Upload media',
            'media:manage_own' => 'Manage own media',
            'media:manage_any' => 'Manage any media',
            'media:delete_own' => 'Delete own media',
        ];
    }

    /**
     * @param array<string> $permissions
     * @return array<string>
     */
    public static function filterScopes(array $permissions): array
    {
        $allowed = array_keys(self::availableScopes());
        $out = [];
        foreach ($permissions as $permission) {
            if (is_string($permission) && in_array($permission, $allowed, true)) {
                $out[] = $permission;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @return array<string>
     */
    public static function defaultReadScopes(): array
    {
        return ['posts:read', 'pages:read', 'media:read'];
    }

    /**
     * Rotate an API key. Returns new plaintext key.
     * Old key remains valid for grace period (TTL = 1 hour).
     */
    public function rotate(string $keyId): string
    {
        $stmt = $this->pdo->prepare('SELECT user_id, name, permissions FROM api_keys WHERE id = ?');
        $stmt->execute([$keyId]);
        $old = $stmt->fetch();
        if ($old === false) {
            throw new \TypeDock\Exception\NotFoundException('API key not found');
        }

        $perms  = $old['permissions'] !== null ? json_decode((string) $old['permissions'], true) : null;
        $result = $this->create((string) $old['user_id'], (string) $old['name'] . ' (rotated)', $perms);

        // Mark old key for expiry after 1 hour grace period
        $gracePeriod = (new \DateTimeImmutable())->modify('+1 hour')->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE api_keys SET expires_at = ? WHERE id = ?')
            ->execute([$gracePeriod, $keyId]);

        return $result['key'];
    }

    public function revoke(string $keyId): void
    {
        $this->pdo->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$keyId]);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listByUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, key_prefix, permissions, expires_at, last_used_at, created_at
             FROM api_keys WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }
}
