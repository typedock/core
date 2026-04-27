<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Form;

use Ramsey\Uuid\Uuid;

/**
 * Honeypot + IP rate-limit check for form submissions. Absorbs the former
 * Antispam module (doc28 §1.3) into a plugin-owned table.
 */
class FormAntispam
{
    private const TABLE = 'plugin_form_antispam_log';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $honeypotField = 'website',
        private readonly int $rateLimit = 5,
        private readonly int $windowSeconds = 60
    ) {}

    public function honeypotFieldName(): string
    {
        return $this->honeypotField;
    }

    /**
     * @param  array<string, mixed> $payload
     * @return array{ok: bool, reason?: string}
     */
    public function check(array $payload, string $scope): array
    {
        if (!empty($payload[$this->honeypotField])) {
            return ['ok' => false, 'reason' => 'honeypot'];
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if ($this->isRateLimited($ip, $scope)) {
            return ['ok' => false, 'reason' => 'rate_limited'];
        }
        $this->logRequest($ip, $scope);

        return ['ok' => true];
    }

    private function isRateLimited(string $ip, string $scope): bool
    {
        try {
            $since = (new \DateTimeImmutable('-' . $this->windowSeconds . ' seconds'))->format('Y-m-d H:i:s');
            $stmt  = $this->pdo->prepare(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ip_address = ? AND scope = ? AND created_at >= ?'
            );
            $stmt->execute([$ip, $scope, $since]);
            return ((int) $stmt->fetchColumn()) >= $this->rateLimit;
        } catch (\Throwable) {
            return false;
        }
    }

    private function logRequest(string $ip, string $scope): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (id, ip_address, scope, created_at) VALUES (?, ?, ?, ?)'
            )->execute([
                Uuid::uuid7()->toString(),
                $ip,
                $scope,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // non-fatal
        }
    }
}
