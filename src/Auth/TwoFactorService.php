<?php
declare(strict_types=1);

namespace TypeDock\Auth;

class TwoFactorService
{
    private const CODE_LENGTH  = 6;
    private const CODE_EXPIRY  = 600; // 10 minutes

    public function __construct(
        private readonly \PDO $pdo,
        private readonly \TypeDock\Contract\MailerInterface $mailer
    ) {}

    /**
     * Send 2FA code to user's email.
     */
    public function sendCode(string $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT email, name FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user === false) {
            throw new \TypeDock\Exception\NotFoundException('User not found');
        }

        $code      = str_pad((string) random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $codeHash  = hash('sha256', $code);
        $expiresAt = (new \DateTimeImmutable())->modify('+' . self::CODE_EXPIRY . ' seconds')->format('Y-m-d H:i:s');

        // Store code in sessions table (reuse as temp storage) or dedicated column
        // Using a separate approach: store in users.two_factor_secret temporarily
        $payload = json_encode(['hash' => $codeHash, 'expires_at' => $expiresAt]);
        $this->pdo->prepare('UPDATE users SET two_factor_secret = ? WHERE id = ?')
            ->execute([$payload, $userId]);

        $siteName = config('app.name', 'TypeDock');
        $this->mailer->send(
            (string) $user['email'],
            "[{$siteName}] Login Verification Code",
            "Login verification code: {$code}\n\nThis code is valid for 10 minutes.\n\nIf you did not request this, please ignore this email."
        );
    }

    /**
     * Verify a 2FA code. Returns true on a valid code.
     *
     * Brute-force protection: failed attempts (wrong code, expired payload,
     * missing payload) increment the same `users.login_attempts` counter the
     * password step uses, and a successful verify resets it. Once the counter
     * crosses the configured threshold, `users.locked_until` is set and the
     * next call throws `TypeDockException(429)` — the controller surfaces
     * this as "account locked" and bounces the user back to /admin/login.
     *
     * Reusing the password counter is intentional: a single attacker should
     * not be allowed to spend their lockout budget on the password step *and*
     * a fresh budget on the 2FA step. The two stages share an account-wide
     * "bad request" budget.
     *
     * @throws \TypeDock\Exception\TypeDockException When the user is locked.
     */
    public function verifyCode(string $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT two_factor_secret, login_attempts, locked_until FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user === false) {
            return false;
        }

        if (!empty($user['locked_until']) && strtotime((string) $user['locked_until']) > time()) {
            throw new \TypeDock\Exception\TypeDockException(
                'Account locked due to too many failed attempts',
                429
            );
        }

        $valid = $this->codeMatches($user['two_factor_secret'] ?? null, $code);
        if (!$valid) {
            $this->onFailedAttempt($userId, (int) ($user['login_attempts'] ?? 0));
            return false;
        }

        // Invalidate code after use and reset the shared attempt counter so
        // future password / 2FA challenges start from zero.
        $this->pdo->prepare(
            'UPDATE users SET two_factor_secret = NULL, login_attempts = 0, locked_until = NULL WHERE id = ?'
        )->execute([$userId]);

        return true;
    }

    private function codeMatches(mixed $secretJson, string $code): bool
    {
        if (empty($secretJson)) {
            return false;
        }
        $payload = json_decode((string) $secretJson, true);
        if (!is_array($payload) || empty($payload['hash']) || empty($payload['expires_at'])) {
            return false;
        }
        if (strtotime((string) $payload['expires_at']) < time()) {
            return false;
        }
        return hash_equals((string) $payload['hash'], hash('sha256', $code));
    }

    private function onFailedAttempt(string $userId, int $previousAttempts): void
    {
        $maxAttempts = (int) config('auth.brute_force.max_attempts', 5);
        $lockoutTime = (int) config('auth.brute_force.lockout_time', 900);
        $attempts    = $previousAttempts + 1;

        if ($attempts >= $maxAttempts) {
            $lockedUntil = (new \DateTimeImmutable())
                ->modify("+{$lockoutTime} seconds")
                ->format('Y-m-d H:i:s');
            $this->pdo->prepare(
                'UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?'
            )->execute([$attempts, $lockedUntil, $userId]);
        } else {
            $this->pdo->prepare('UPDATE users SET login_attempts = ? WHERE id = ?')
                ->execute([$attempts, $userId]);
        }
    }

    /**
     * Enable or disable 2FA for a user.
     */
    public function setEnabled(string $userId, bool $enabled): void
    {
        $this->pdo->prepare('UPDATE users SET two_factor_enabled = ? WHERE id = ?')
            ->execute([$enabled ? 1 : 0, $userId]);
    }
}
