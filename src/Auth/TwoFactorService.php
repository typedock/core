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
     * Verify a 2FA code. Returns true if valid.
     */
    public function verifyCode(string $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT two_factor_secret FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if ($user === false || empty($user['two_factor_secret'])) {
            return false;
        }

        $payload = json_decode((string) $user['two_factor_secret'], true);
        if (!is_array($payload) || empty($payload['hash']) || empty($payload['expires_at'])) {
            return false;
        }

        // Check expiry
        if (strtotime((string) $payload['expires_at']) < time()) {
            return false;
        }

        // Verify code
        $codeHash = hash('sha256', $code);
        if (!hash_equals((string) $payload['hash'], $codeHash)) {
            return false;
        }

        // Invalidate code after use
        $this->pdo->prepare('UPDATE users SET two_factor_secret = NULL WHERE id = ?')
            ->execute([$userId]);

        return true;
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
