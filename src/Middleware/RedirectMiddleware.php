<?php
declare(strict_types=1);

namespace TypeDock\Middleware;

use TypeDock\Contract\RedirectResolver;

class RedirectMiddleware
{
    /** @var RedirectResolver[] */
    private static array $resolvers = [];

    public static function addResolver(RedirectResolver $resolver): void
    {
        self::$resolvers[] = $resolver;
    }

    public function handle(): void
    {
        $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if ($method !== 'GET') {
            return;
        }

        // Skip admin and API routes
        if (str_starts_with($uri, '/admin') || str_starts_with($uri, '/api')) {
            return;
        }

        // Check Core redirects (exact match)
        $result = $this->checkCoreRedirects($uri);
        if ($result !== null) {
            [$target, $code] = $result;
            header('Location: ' . $target, true, $code);
            exit;
        }

        // Check module/plugin resolvers (filter chain)
        foreach (self::$resolvers as $resolver) {
            $result = $resolver->resolve($uri);
            if ($result !== null) {
                [$target, $code] = $result;
                header('Location: ' . $target, true, $code);
                exit;
            }
        }
    }

    /** @return array{0: string, 1: int}|null */
    private function checkCoreRedirects(string $sourcePath): ?array
    {
        try {
            $pdo  = \Flight::db();
            $stmt = $pdo->prepare(
                'SELECT target_url, status_code FROM redirects WHERE source_path = ? LIMIT 1'
            );
            $stmt->execute([$sourcePath]);
            $row = $stmt->fetch();
            if ($row === false) {
                return null;
            }
            return [(string) $row['target_url'], (int) $row['status_code']];
        } catch (\Throwable) {
            return null;
        }
    }
}
