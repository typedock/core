<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Redirect;

use TypeDock\Contract\RedirectResolver;

final class RegexResolver implements RedirectResolver
{
    public function __construct(private readonly \PDO $pdo) {}

    public function resolve(string $sourcePath): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT source_path, target_url, status_code FROM redirects
                  WHERE source_path LIKE '~%'
                  ORDER BY created_at ASC
                  LIMIT " . RegexPattern::MAX_RULES
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            $pattern = substr((string) $row['source_path'], 1);
            $regex   = RegexPattern::compile($pattern);
            if ($regex === null) {
                continue;
            }

            $matches = [];
            if (@preg_match($regex, $sourcePath, $matches) === 1) {
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
