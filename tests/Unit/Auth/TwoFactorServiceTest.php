<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use TypeDock\Auth\TwoFactorService;
use TypeDock\Contract\MailerInterface;
use TypeDock\Exception\TypeDockException;

/**
 * The 2FA verify path is a brute-force boundary: a 6-digit code is only
 * 1M possibilities, so without a counter an attacker who has the password
 * just keeps submitting until they hit. These tests pin:
 *
 *   - bad codes increment users.login_attempts
 *   - hitting the threshold sets users.locked_until and the next call throws
 *   - successful verify resets the counter so legitimate users aren't punished
 *     for one typo before getting it right
 *
 * The counter is shared with the password step on purpose (see service
 * docblock). Lockout config falls back to the same `auth.brute_force` keys
 * SessionService uses.
 */
final class TwoFactorServiceTest extends TestCase
{
    private \PDO $pdo;
    private TwoFactorService $service;
    private string $userId = '00000000-0000-7000-8000-000000000001';

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec(
            'CREATE TABLE users (
                id TEXT PRIMARY KEY,
                two_factor_secret TEXT NULL,
                two_factor_enabled INTEGER DEFAULT 0,
                login_attempts INTEGER DEFAULT 0,
                locked_until TEXT NULL
            )'
        );
        $this->service = new TwoFactorService($this->pdo, $this->mailer());
    }

    public function test_correct_code_succeeds_and_resets_counter(): void
    {
        $this->seedUser(loginAttempts: 3);
        $this->seedCode('123456');

        self::assertTrue($this->service->verifyCode($this->userId, '123456'));

        $row = $this->fetchUser();
        self::assertSame(0, (int) $row['login_attempts']);
        self::assertNull($row['locked_until']);
        self::assertNull($row['two_factor_secret'], 'Code is single-use.');
    }

    public function test_wrong_code_increments_counter(): void
    {
        $this->seedUser(loginAttempts: 0);
        $this->seedCode('123456');

        self::assertFalse($this->service->verifyCode($this->userId, '999999'));

        $row = $this->fetchUser();
        self::assertSame(1, (int) $row['login_attempts']);
        self::assertNull($row['locked_until']);
    }

    public function test_threshold_locks_account(): void
    {
        // 4 prior failures + this 5th one = max_attempts (5 by default)
        $this->seedUser(loginAttempts: 4);
        $this->seedCode('123456');

        self::assertFalse($this->service->verifyCode($this->userId, 'wrong1'));

        $row = $this->fetchUser();
        self::assertSame(5, (int) $row['login_attempts']);
        self::assertNotNull($row['locked_until'], 'Account must be locked at threshold.');
    }

    public function test_locked_account_throws_before_checking_code(): void
    {
        $futureLock = (new \DateTimeImmutable())->modify('+15 minutes')->format('Y-m-d H:i:s');
        $this->seedUser(loginAttempts: 5, lockedUntil: $futureLock);
        // Even a *correct* code shouldn't be honoured during lockout.
        $this->seedCode('123456');

        $this->expectException(TypeDockException::class);
        $this->expectExceptionCode(429);
        $this->service->verifyCode($this->userId, '123456');
    }

    public function test_expired_lockout_does_not_throw(): void
    {
        $pastLock = (new \DateTimeImmutable())->modify('-15 minutes')->format('Y-m-d H:i:s');
        $this->seedUser(loginAttempts: 5, lockedUntil: $pastLock);
        $this->seedCode('123456');

        // Past lockout means the user can try again. Correct code wins.
        self::assertTrue($this->service->verifyCode($this->userId, '123456'));
    }

    public function test_expired_code_counts_as_failed_attempt(): void
    {
        $this->seedUser(loginAttempts: 0);
        // Seed a code whose expiry is in the past.
        $this->seedCode('123456', expiresAt: (new \DateTimeImmutable())->modify('-1 minute'));

        self::assertFalse($this->service->verifyCode($this->userId, '123456'));
        self::assertSame(1, (int) $this->fetchUser()['login_attempts']);
    }

    private function seedUser(int $loginAttempts = 0, ?string $lockedUntil = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO users (id, login_attempts, locked_until) VALUES (?, ?, ?)'
        )->execute([$this->userId, $loginAttempts, $lockedUntil]);
    }

    private function seedCode(string $code, ?\DateTimeImmutable $expiresAt = null): void
    {
        $expiresAt ??= (new \DateTimeImmutable())->modify('+10 minutes');
        $payload = json_encode([
            'hash'       => hash('sha256', $code),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);
        $this->pdo->prepare('UPDATE users SET two_factor_secret = ? WHERE id = ?')
            ->execute([$payload, $this->userId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchUser(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$this->userId]);
        $row = $stmt->fetch();
        self::assertIsArray($row);
        return $row;
    }

    private function mailer(): MailerInterface
    {
        return new class implements MailerInterface {
            public function send(string $to, string $subject, string $body, array $options = []): bool
            {
                return true;
            }
        };
    }
}
