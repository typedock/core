<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Redirect;

use TypeDock\Contract\RedirectResolver;

final class ExactMatchResolver implements RedirectResolver
{
    public function __construct(private readonly \PDO $pdo) {}

    public function resolve(string $sourcePath): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT target_url, status_code FROM redirects
                 WHERE source_path = ? AND source_path NOT LIKE \'~%\' LIMIT 1'
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
