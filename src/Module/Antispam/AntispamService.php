<?php
declare(strict_types=1);

namespace TypeDock\Module\Antispam;

use Ramsey\Uuid\Uuid;
use TypeDock\Contract\SpamCheckerInterface;

/**
 * AntispamService — combines:
 *   1. Honeypot field check (a field that bots fill but humans don't).
 *   2. IP-based rate limiting using the antispam_log table.
 *   3. Optional pluggable spam checkers (Akismet-style) via SpamCheckerInterface.
 */
class AntispamService
{
    /** @var SpamCheckerInterface[] */
    private array $checkers = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $honeypotField = 'website',
        private readonly int $rateLimit = 5,
        private readonly int $windowSeconds = 60
    ) {}

    public function addChecker(SpamCheckerInterface $checker): void
    {
        $this->checkers[] = $checker;
    }

    /**
     * @param  array<string, mixed> $payload Form payload (POST data).
     * @param  string               $scope   Logical bucket for rate-limiting (e.g. "comment", "contact").
     * @return array{ok: bool, reason?: string}
     */
    public function check(array $payload, string $scope = 'default'): array
    {
        // 1. Honeypot
        if (!empty($payload[$this->honeypotField])) {
            return ['ok' => false, 'reason' => 'honeypot'];
        }

        // 2. Rate limiting
        $ip = $this->clientIp();
        if ($this->isRateLimited($ip, $scope)) {
            return ['ok' => false, 'reason' => 'rate_limited'];
        }
        $this->logRequest($ip, $scope);

        // 3. External spam checkers
        foreach ($this->checkers as $checker) {
            if ($checker->isSpam($payload)) {
                return ['ok' => false, 'reason' => 'spam_detected'];
            }
        }

        return ['ok' => true];
    }

    public function honeypotFieldName(): string
    {
        return $this->honeypotField;
    }

    private function isRateLimited(string $ip, string $scope): bool
    {
        try {
            $since = (new \DateTimeImmutable('-' . $this->windowSeconds . ' seconds'))->format('Y-m-d H:i:s');
            $stmt  = $this->pdo->prepare(
                'SELECT COUNT(*) FROM antispam_log WHERE ip_address = ? AND scope = ? AND created_at >= ?'
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
                'INSERT INTO antispam_log (id, ip_address, scope, created_at) VALUES (?, ?, ?, ?)'
            )->execute([
                Uuid::uuid7()->toString(),
                $ip,
                $scope,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // ignore log failures
        }
    }

    public function purgeOld(int $olderThanSeconds = 3600): int
    {
        try {
            $cutoff = (new \DateTimeImmutable('-' . $olderThanSeconds . ' seconds'))->format('Y-m-d H:i:s');
            $stmt   = $this->pdo->prepare('DELETE FROM antispam_log WHERE created_at < ?');
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
