<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

class AuthMiddleware
{
    public function handle(): void
    {
        // Handled per-route via requireAuth()
    }

    /**
     * Flight group-middleware entry. Enforces session auth for every route in
     * the admin HTML group; unauthenticated requests are redirected to the
     * login screen before the route handler runs.
     *
     * @param array<string,string> $params Route params (unused)
     */
    public function before(array $params = []): void
    {
        $this->requireAuth();
    }

    public function requireAuth(?string $requiredRole = null): void
    {
        $user = $this->getCurrentUser();

        if ($user === null) {
            \Flight::redirect('/admin/login');
            exit;
        }

        if ($requiredRole !== null && !$this->hasRole($user, $requiredRole)) {
            throw new \TypeDock\Exception\ForbiddenException('Insufficient permissions');
        }

        \Flight::set('current_user', $user);
    }

    public function requireAuthJson(?string $requiredRole = null): void
    {
        $user = $this->getCurrentUser();

        if ($user === null) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        if ($requiredRole !== null && !$this->hasRole($user, $requiredRole)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }

        \Flight::set('current_user', $user);
    }

    public function requirePermission(string $permission): void
    {
        $this->requireAuth();
        $user = \Flight::get('current_user');
        if (!\Flight::permissions()->can($user, $permission)) {
            throw new \TypeDock\Exception\ForbiddenException("Missing permission: {$permission}");
        }
    }

    public function requirePermissionJson(string $permission): void
    {
        $this->requireAuthJson();
        $user = \Flight::get('current_user');
        if (!\Flight::permissions()->can($user, $permission)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
            exit;
        }
    }

    public function requireApiKey(?string $permission = null): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            http_response_code(401);
            header('Content-Type: application/json');
            header('WWW-Authenticate: Bearer realm="TypeDock API"');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        $token  = substr($header, 7);
        $result = \Flight::apikey()->validate($token, $permission);

        if ($result === null) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid or expired API key']);
            exit;
        }

        \Flight::set('current_api_user', $result);
    }

    private function getCurrentUser(): ?array
    {
        $cookieName = config('auth.cookie_name', 'typedock_auth');
        $token      = $_COOKIE[$cookieName] ?? null;

        if ($token === null || $token === '') {
            return null;
        }

        return \Flight::session()->check($token);
    }

    private function hasRole(array $user, string $requiredRole): bool
    {
        return \Flight::permissions()->can($user, 'role:' . $requiredRole);
    }
}
