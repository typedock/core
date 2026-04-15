<?php
declare(strict_types=1);

namespace TypeDock\Auth;

class PasswordResetService
{
    private const TOKEN_EXPIRY = 3600; // 1 hour

    public function __construct(
        private readonly \PDO $pdo,
        private readonly \TypeDock\Contract\MailerInterface $mailer
    ) {}

    /**
     * Send password reset email. Returns false if email not found (but don't reveal this to user).
     */
    public function request(string $email): void
    {
        $stmt = $this->pdo->prepare('SELECT id, name FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Don't reveal if email exists
        if ($user === false) {
            return;
        }

        $token      = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $token);
        $id         = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $expiresAt  = (new \DateTimeImmutable())->modify('+' . self::TOKEN_EXPIRY . ' seconds')->format('Y-m-d H:i:s');
        $now        = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Invalidate previous tokens for this user
        $this->pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')
            ->execute([$user['id']]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (id, user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$id, $user['id'], $tokenHash, $expiresAt, $now]);

        $siteUrl  = config('app.url', 'http://localhost');
        $siteName = config('app.name', 'TypeDock');
        $resetUrl = $siteUrl . '/admin/password-reset?token=' . urlencode($token);

        $this->mailer->send(
            $email,
            "[{$siteName}] Password Reset",
            "Password reset link:\n{$resetUrl}\n\nThis link is valid for 1 hour.\n\nIf you did not request this, please ignore this email."
        );
    }

    /**
     * Verify reset token. Returns user_id or null.
     */
    public function verify(string $token): ?string
    {
        $tokenHash = hash('sha256', $token);
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM password_resets
             WHERE token_hash = ? AND expires_at > ? AND used_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([$tokenHash, $now]);
        $row = $stmt->fetch();

        return $row !== false ? (string) $row['user_id'] : null;
    }

    /**
     * Reset password using token.
     */
    public function reset(string $token, string $newPassword): bool
    {
        $userId = $this->verify($token);
        if ($userId === null) {
            return false;
        }

        $algo       = config('auth.hash_algo', 'bcrypt');
        $options    = $algo === 'argon2' ? ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1] : [];
        $algo_const = $algo === 'argon2' ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

        $hash = password_hash($newPassword, $algo_const, $options);
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Update password
        $this->pdo->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, $now, $userId]);

        // Mark token as used
        $tokenHash = hash('sha256', $token);
        $this->pdo->prepare('UPDATE password_resets SET used_at = ? WHERE token_hash = ?')
            ->execute([$now, $tokenHash]);

        // Invalidate all sessions for this user
        $this->pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$userId]);

        return true;
    }
}
