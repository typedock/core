<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Middleware\CsrfMiddleware;

class AuthController
{
    public function showLogin(): void
    {
        if ($this->isLoggedIn()) {
            \Flight::redirect('/admin/dashboard');
            return;
        }

        \Flight::latte()->render('pages/login.latte', [
            'site'       => $this->getSiteData(),
            'csrf_token' => CsrfMiddleware::generate(),
        ], TYPEDOCK_ROOT . '/admin');
    }

    public function processLogin(): void
    {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            \Flight::latte()->render('pages/login.latte', [
                'site'       => $this->getSiteData(),
                'csrf_token' => CsrfMiddleware::generate(),
                'error'      => 'Please enter your email address and password.',
                'old_email'  => $email,
            ], TYPEDOCK_ROOT . '/admin');
            return;
        }

        try {
            $result = \Flight::session()->login($email, $password);
        } catch (\TypeDock\Exception\TypeDockException $e) {
            \Flight::latte()->render('pages/login.latte', [
                'site'       => $this->getSiteData(),
                'csrf_token' => CsrfMiddleware::generate(),
                'error'      => 'Your account is locked. Please try again later.',
                'old_email'  => $email,
            ], TYPEDOCK_ROOT . '/admin');
            return;
        }

        if ($result === null) {
            \Flight::latte()->render('pages/login.latte', [
                'site'       => $this->getSiteData(),
                'csrf_token' => CsrfMiddleware::generate(),
                'error'      => 'Incorrect email address or password.',
                'old_email'  => $email,
            ], TYPEDOCK_ROOT . '/admin');
            return;
        }

        // 2FA required
        if (str_starts_with($result, 'NEEDS_2FA:')) {
            $userId = substr($result, 10);
            // Store user_id in PHP session for 2FA step
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['pending_2fa_user_id'] = $userId;

            // Send 2FA code
            try {
                $mailer    = new \TypeDock\Mail\PhpMailer();
                $tfService = new \TypeDock\Auth\TwoFactorService(\Flight::db(), $mailer);
                $tfService->sendCode($userId);
            } catch (\Throwable) {
                // Log error but continue
            }

            \Flight::redirect('/admin/login/2fa');
            return;
        }

        \Flight::redirect('/admin/dashboard');
    }

    public function showTwoFactor(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['pending_2fa_user_id'])) {
            \Flight::redirect('/admin/login');
            return;
        }

        \Flight::latte()->render('pages/login-2fa.latte', [
            'site'       => $this->getSiteData(),
            'csrf_token' => CsrfMiddleware::generate(),
            'user_id'    => $_SESSION['pending_2fa_user_id'],
        ], TYPEDOCK_ROOT . '/admin');
    }

    public function processTwoFactor(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        if ($userId === null) {
            \Flight::redirect('/admin/login');
            return;
        }

        $code = trim($_POST['code'] ?? '');

        $mailer    = new \TypeDock\Mail\PhpMailer();
        $tfService = new \TypeDock\Auth\TwoFactorService(\Flight::db(), $mailer);

        if (!$tfService->verifyCode((string) $userId, $code)) {
            \Flight::latte()->render('pages/login-2fa.latte', [
                'site'       => $this->getSiteData(),
                'csrf_token' => CsrfMiddleware::generate(),
                'user_id'    => $userId,
                'error'      => 'The verification code is incorrect.',
            ], TYPEDOCK_ROOT . '/admin');
            return;
        }

        unset($_SESSION['pending_2fa_user_id']);

        // Create session with 2FA verified
        \Flight::session()->createSession((string) $userId, true);
        \Flight::redirect('/admin/dashboard');
    }

    public function logout(): void
    {
        $cookieName = (string) config('auth.cookie_name', 'cms_session');
        $token      = $_COOKIE[$cookieName] ?? '';
        if ($token !== '') {
            \Flight::session()->logout($token);
        }
        \Flight::redirect('/admin/login');
    }

    private function isLoggedIn(): bool
    {
        $cookieName = (string) config('auth.cookie_name', 'cms_session');
        $token      = $_COOKIE[$cookieName] ?? null;
        if ($token === null) {
            return false;
        }
        return \Flight::session()->check($token) !== null;
    }

    private function getSiteData(): object
    {
        try {
            $stmt = \Flight::db()->prepare("SELECT key_name, value FROM site_options WHERE group_name = 'general'");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $opts = [];
            foreach ($rows as $row) {
                $opts[$row['key_name']] = json_decode((string) $row['value'], true);
            }
        } catch (\Throwable) {
            $opts = [];
        }

        return (object) [
            'name' => $opts['site.name'] ?? config('app.name', 'TypeDock'),
            'url'  => config('app.url', 'http://localhost'),
        ];
    }
}
