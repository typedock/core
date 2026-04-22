<?php
declare(strict_types=1);

namespace TypeDock\Module\Redirect;

use TypeDock\Contract\RedirectResolver;

/**
 * Resolves redirects by matching source_path stored as a regex pattern.
 * A row is treated as regex when source_path begins with "~" (e.g. "~^/old/(.*)$").
 * The target_url may reference capture groups via $1, $2, ...
 */
class RegexRedirectResolver implements RedirectResolver
{
    public function __construct(private readonly \PDO $pdo) {}

    public function resolve(string $sourcePath): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT source_path, target_url, status_code FROM redirects WHERE source_path LIKE '~%'"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            $pattern = substr((string) $row['source_path'], 1);
            $delim   = '#';
            $regex   = $delim . str_replace($delim, '\\' . $delim, $pattern) . $delim;

            $matches = [];
            $ok      = @preg_match($regex, $sourcePath, $matches);
            if ($ok === 1) {
                $target = (string) $row['target_url'];
                foreach ($matches as $i => $m) {
                    if ($i === 0) {
                        continue;
                    }
                    $target = str_replace('$' . $i, (string) $m, $target);
                }
                return [$target, (int) $row['status_code']];
            }
        }
        return null;
    }
}
