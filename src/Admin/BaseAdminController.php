<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Middleware\CsrfMiddleware;

abstract class BaseAdminController
{
    /**
     * @param array<string, mixed> $params
     */
    protected function render(string $template, array $params = []): void
    {
        $user    = \Flight::get('current_user');
        $siteObj = $this->buildSiteObject();

        $defaults = [
            'current_user' => $user,
            'site'         => $siteObj,
            'csrf_token'   => CsrfMiddleware::generate(),
        ];

        \Flight::latte()->render($template, array_merge($defaults, $params), TYPEDOCK_ROOT . '/admin');
    }

    protected function redirect(string $path, string $message = '', string $type = 'success'): void
    {
        if ($message !== '') {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $_SESSION['flash_' . $type] = $message;
        }
        \Flight::redirect($path);
        exit;
    }

    protected function getFlash(string $type = 'success'): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $key = 'flash_' . $type;
        $msg = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $msg;
    }

    private function buildSiteObject(): object
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
