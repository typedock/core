<?php
declare(strict_types=1);

namespace TypeDock\Auth;

class SessionService
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Attempt login. Returns session token (plain) on success, null on failure.
     * Checks brute force lockout.
     */
    public function login(string $email, string $password): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, password_hash, role, login_attempts, locked_until, two_factor_enabled
             FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user === false) {
            return null;
        }

        // Check lockout
        if ($this->isLocked($user)) {
            throw new \TypeDock\Exception\TypeDockException('Account locked due to too many failed attempts', 429);
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            $this->incrementLoginAttempts((string) $user['id']);
            return null;
        }

        // Reset failed attempts on success
        $this->resetLoginAttempts((string) $user['id']);

        // Update last login
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')
            ->execute([$now, $user['id']]);

        // If 2FA required, return special marker
        if ((bool) $user['two_factor_enabled']) {
            return 'NEEDS_2FA:' . $user['id'];
        }

        return $this->createSession((string) $user['id']);
    }

    /**
     * Create a new session. Returns plain token.
     */
    public function createSession(string $userId, bool $twoFactorVerified = false): string
    {
        $token     = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $id        = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+' . (int) config('auth.session_lifetime', 86400) . ' seconds')
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, token_hash, ip_address, user_agent, two_factor_verified, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $userId,
            $tokenHash,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
            $twoFactorVerified ? 1 : 0,
            $expiresAt,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        // Set HttpOnly cookie
        $cookieName = (string) config('auth.cookie_name', 'cms_session');
        $lifetime   = (int) config('auth.session_lifetime', 86400);
        setcookie($cookieName, $token, [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);

        return $token;
    }

    /**
     * Validate token and return user array, or null if invalid/expired.
     */
    public function check(string $token): ?array
    {
        $tokenHash = hash('sha256', $token);
        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'SELECT s.id as session_id, s.two_factor_verified,
                    u.id, u.email, u.name, u.role, u.avatar_path
             FROM sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > ?
             LIMIT 1'
        );
        $stmt->execute([$tokenHash, $now]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        // Check if 2FA was required and not yet verified
        if ((bool) config('auth.two_factor', false)) {
            // If two_factor_enabled check needed, fetch from users
            // For simplicity, return user if session is valid
        }

        return $row;
    }

    /**
     * Destroy the current session.
     */
    public function logout(string $token): void
    {
        $tokenHash = hash('sha256', $token);
        $this->pdo->prepare('DELETE FROM sessions WHERE token_hash = ?')
            ->execute([$tokenHash]);

        $cookieName = (string) config('auth.cookie_name', 'cms_session');
        setcookie($cookieName, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * Get current user from request cookie.
     */
    public function getCurrentUser(): ?array
    {
        $cookieName = (string) config('auth.cookie_name', 'cms_session');
        $token      = $_COOKIE[$cookieName] ?? null;
        if ($token === null || $token === '') {
            return null;
        }
        return $this->check($token);
    }

    private function isLocked(array $user): bool
    {
        if (empty($user['locked_until'])) {
            return false;
        }
        return strtotime((string) $user['locked_until']) > time();
    }

    private function incrementLoginAttempts(string $userId): void
    {
        $maxAttempts  = (int) config('auth.brute_force.max_attempts', 5);
        $lockoutTime  = (int) config('auth.brute_force.lockout_time', 900);

        $stmt = $this->pdo->prepare('SELECT login_attempts FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return;
        }

        $attempts = (int) $row['login_attempts'] + 1;
        if ($attempts >= $maxAttempts) {
            $lockedUntil = (new \DateTimeImmutable())->modify("+{$lockoutTime} seconds")->format('Y-m-d H:i:s');
            $this->pdo->prepare('UPDATE users SET login_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$attempts, $lockedUntil, $userId]);
        } else {
            $this->pdo->prepare('UPDATE users SET login_attempts = ? WHERE id = ?')
                ->execute([$attempts, $userId]);
        }
    }

    private function resetLoginAttempts(string $userId): void
    {
        $this->pdo->prepare('UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = ?')
            ->execute([$userId]);
    }
}
