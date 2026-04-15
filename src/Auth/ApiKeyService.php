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
     * @return array{user_id: string, permissions: array<string>|null}|null
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
            'SELECT id, user_id, key_hash, permissions, expires_at
             FROM api_keys
             WHERE key_prefix = ? AND (expires_at IS NULL OR expires_at > ?)
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

        $permissions = $matched['permissions'] !== null
            ? json_decode((string) $matched['permissions'], true)
            : null;

        // Check specific permission if required
        if ($requiredPermission !== null && is_array($permissions)) {
            if (!in_array($requiredPermission, $permissions, true)) {
                return null;
            }
        }

        return [
            'user_id'     => (string) $matched['user_id'],
            'permissions' => $permissions,
            'api_key_id'  => (string) $matched['id'],
        ];
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
