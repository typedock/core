<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

class CsrfMiddleware
{
    private const TOKEN_KEY = '_csrf_token';

    public function verifyOrFail(): void
    {
        if (!$this->verify()) {
            http_response_code(419);
            throw new \TypeDock\Exception\TypeDockException('CSRF token mismatch', 419);
        }
    }

    public function verify(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sessionToken = $_SESSION[self::TOKEN_KEY] ?? null;
        $requestToken = $_POST['_csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if ($sessionToken === null || $requestToken === null) {
            return false;
        }

        return hash_equals($sessionToken, $requestToken);
    }

    public static function generate(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::TOKEN_KEY];
    }

    public static function getToken(): string
    {
        return $_SESSION[self::TOKEN_KEY] ?? '';
    }
}
