<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class AuthorProfileProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        if ($context->page === null) {
            return ['author' => null];
        }

        $authorId = $context->page['author_id'] ?? null;
        if ($authorId === null || $authorId === '') {
            return ['author' => null];
        }

        $stmt = \Flight::db()->prepare(
            'SELECT name, avatar_path FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$authorId]);
        $row = $stmt->fetch();

        return ['author' => $row !== false ? $row : null];
    }
}
