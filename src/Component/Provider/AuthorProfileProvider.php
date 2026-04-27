<?php
declare(strict_types=1);

namespace TypeDock\Component\Provider;

use TypeDock\Component\DataProvider;
use TypeDock\Component\RenderContext;

class AuthorProfileProvider implements DataProvider
{
    public function resolve(array $params, RenderContext $context): array
    {
        $authorId = (string) ($params['user_id'] ?? $context->page['author_id'] ?? '');
        if ($authorId === '') {
            return ['author' => null];
        }

        $stmt = \Flight::db()->prepare(
            'SELECT u.id, u.name, u.display_name, u.slug, u.bio, u.website_url, u.social_links,
                    u.avatar_path, m.path AS avatar_media_path
             FROM users u
             LEFT JOIN media m ON m.id = u.avatar_media_id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$authorId]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $row['display_name'] = $row['display_name'] ?: $row['name'];
            if (!empty($row['avatar_media_path'])) {
                $row['avatar_url'] = \Flight::storage()->url((string) $row['avatar_media_path']);
            } elseif (!empty($row['avatar_path'])) {
                $row['avatar_url'] = $row['avatar_path'];
            } else {
                $row['avatar_url'] = null;
            }
            $links = json_decode((string) ($row['social_links'] ?? ''), true);
            $row['social_links'] = is_array($links) ? $links : [];
        }

        return ['author' => $row !== false ? $row : null];
    }
}
