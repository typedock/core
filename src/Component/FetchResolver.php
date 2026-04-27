<?php
declare(strict_types=1);

namespace TypeDock\Component;

/**
 * Resolve the declarative `fetch:` section that theme.json templates and
 * custom components use to ask for data without writing any PHP.
 *
 * Each key in the fetch definition describes one data source call:
 *
 *   "latest_posts": {
 *     "source": "posts",
 *     "params": { "limit": 5, "post_type": "post" },
 *     "sort": "-published_at"
 *   }
 *
 * The resolver interpolates `{{params.xxx}}` / `{{context.xxx}}` placeholders
 * against the calling component's user params + the RenderContext, then
 * dispatches to a handler per source. The return value is an object whose
 * public properties mirror the fetch keys so templates can write
 * `$fetch->latest_posts`.
 */
class FetchResolver
{
    /**
     * @param array<string, array<string, mixed>>|null $fetchDefs
     * @param array<string, mixed>                     $userParams
     */
    public function resolve(?array $fetchDefs, array $userParams, RenderContext $ctx): object
    {
        $out = new \stdClass();
        if (empty($fetchDefs)) {
            return $out;
        }

        foreach ($fetchDefs as $key => $def) {
            if (!is_array($def)) {
                $out->{$key} = null;
                continue;
            }

            if (!$this->contextAllowed($def['require_context'] ?? null, $ctx)) {
                $out->{$key} = null;
                continue;
            }

            $source = (string) ($def['source'] ?? '');
            $params = $this->interpolate((array) ($def['params'] ?? []), $userParams, $ctx);
            $sort   = isset($def['sort']) ? (string) $def['sort'] : null;

            $out->{$key} = $this->fetch($source, $params, $sort, $ctx);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $userParams
     * @return array<string, mixed>
     */
    public function interpolate(array $params, array $userParams, RenderContext $ctx): array
    {
        $resolvedContext = [
            'post_id'    => $ctx->page['id'] ?? null,
            'post_type'  => $ctx->postType ?? null,
            'category'   => $ctx->term['slug'] ?? null,
            'tag'        => $ctx->term['slug'] ?? null,
            'locale'     => $ctx->locale,
            'route_type' => $ctx->routeType ?? null,
        ];

        $out = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->interpolate($value, $userParams, $ctx);
                continue;
            }

            if (!is_string($value)) {
                $out[$key] = $value;
                continue;
            }

            if (preg_match('/^\{\{\s*(params|context)\.([\w_]+)\s*\}\}$/', $value, $m)) {
                $scope = $m[1];
                $name  = $m[2];
                $resolved = $scope === 'params'
                    ? ($userParams[$name] ?? null)
                    : ($resolvedContext[$name] ?? null);
                // Drop the key entirely when the value is null/empty string so
                // the data-source query treats the filter as "no constraint".
                if ($resolved === null || $resolved === '') {
                    continue;
                }
                $out[$key] = $resolved;
                continue;
            }

            // Inline interpolation: replace placeholders inside a larger string.
            $rendered = preg_replace_callback(
                '/\{\{\s*(params|context)\.([\w_]+)\s*\}\}/',
                function ($m) use ($userParams, $resolvedContext) {
                    $scope    = $m[1];
                    $name     = $m[2];
                    $resolved = $scope === 'params'
                        ? ($userParams[$name] ?? null)
                        : ($resolvedContext[$name] ?? null);
                    return is_scalar($resolved) ? (string) $resolved : '';
                },
                $value
            );
            if ($rendered !== null && $rendered !== '') {
                $out[$key] = $rendered;
            }
        }
        return $out;
    }

    private function contextAllowed(mixed $requireContext, RenderContext $ctx): bool
    {
        if ($requireContext === null || $requireContext === '') {
            return true;
        }
        $list = is_array($requireContext) ? $requireContext : [$requireContext];
        $actual = $ctx->contextType;
        foreach ($list as $candidate) {
            if ((string) $candidate === $actual) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function fetch(string $source, array $params, ?string $sort, RenderContext $ctx): mixed
    {
        return match ($source) {
            'posts'         => $this->fetchPosts($params, $sort),
            'categories'    => $this->fetchCategories($params, $sort),
            'tags'          => $this->fetchTags($params, $sort),
            'menu'          => $this->fetchMenu($params, $ctx),
            'related_posts' => $this->fetchRelatedPosts($params, $ctx),
            'site_options'  => $this->fetchSiteOptions($params),
            default         => null,
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<object>
     */
    private function fetchPosts(array $params, ?string $sort): array
    {
        $pdo   = \Flight::db();
        $where = ["p.status = 'published'", "p.post_type = ?"];
        $args  = [(string) ($params['post_type'] ?? 'post')];

        if (!empty($params['category'])) {
            $where[] = 'EXISTS (SELECT 1 FROM post_categories pc JOIN categories c ON c.id = pc.category_id WHERE pc.post_id = p.id AND c.slug = ?)';
            $args[]  = (string) $params['category'];
        }
        if (!empty($params['tag'])) {
            $where[] = 'EXISTS (SELECT 1 FROM post_tags pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id = p.id AND t.slug = ?)';
            $args[]  = (string) $params['tag'];
        }

        $limit = max(1, (int) ($params['limit'] ?? 10));
        $order = $this->buildOrderBy($sort, 'p', 'published_at', 'DESC');

        $sql = "SELECT p.*, COALESCE(NULLIF(u.display_name, ''), u.name) as author_name,
                       u.slug as author_slug, sm.og_image_id FROM posts p
                LEFT JOIN users u ON u.id = p.author_id
                LEFT JOIN seo_meta sm ON sm.target_type = p.post_type AND sm.target_id = p.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY {$order}
                LIMIT ?";
        $args[] = $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return \TypeDock\Content\PostView::projectList($stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array<string, mixed>>
     */
    private function fetchCategories(array $params, ?string $sort): array
    {
        $pdo  = \Flight::db();
        $sql  = 'SELECT c.*, (SELECT COUNT(*) FROM post_categories pc WHERE pc.category_id = c.id) AS post_count FROM categories c';
        $where = [];
        $args  = [];

        if (!empty($params['slugs']) && is_array($params['slugs'])) {
            $placeholders = implode(',', array_fill(0, count($params['slugs']), '?'));
            $where[]      = "c.slug IN ($placeholders)";
            $args         = array_merge($args, array_values($params['slugs']));
        }
        if (!empty($params['parent'])) {
            $where[] = 'c.parent_id = (SELECT id FROM categories WHERE slug = ? LIMIT 1)';
            $args[]  = (string) $params['parent'];
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $order = $this->buildOrderBy($sort, 'c', 'sort_order', 'ASC');
        $sql  .= " ORDER BY {$order}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        if (empty($params['show_empty'])) {
            $rows = array_values(array_filter($rows, fn ($r) => (int) $r['post_count'] > 0));
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<array<string, mixed>>
     */
    private function fetchTags(array $params, ?string $sort): array
    {
        $pdo   = \Flight::db();
        $limit = max(1, (int) ($params['limit'] ?? 50));
        $orderBy = match ((string) ($params['order_by'] ?? 'name')) {
            'count' => 'post_count DESC, t.name ASC',
            default => 't.name ASC',
        };
        $order = $this->buildOrderBy($sort, 't', '', '') ?: $orderBy;

        $stmt = $pdo->prepare(
            "SELECT t.*, (SELECT COUNT(*) FROM post_tags pt WHERE pt.tag_id = t.id) AS post_count
             FROM tags t ORDER BY {$order} LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<\TypeDock\Content\MenuItem>
     */
    private function fetchMenu(array $params, RenderContext $ctx): array
    {
        $location = (string) ($params['location'] ?? '');
        if ($location === '') {
            return [];
        }
        $resolver = new \TypeDock\Content\MenuTreeResolver(\Flight::db());
        return $resolver->resolve($location, $ctx->locale ?: 'en');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<object>
     */
    private function fetchRelatedPosts(array $params, RenderContext $ctx): array
    {
        $currentId = $ctx->page['id'] ?? null;
        if ($currentId === null) {
            return [];
        }

        $pdo   = \Flight::db();
        $by    = (string) ($params['by'] ?? 'category');
        $limit = max(1, (int) ($params['limit'] ?? 5));

        $joinTable = $by === 'tag' ? 'post_tags' : 'post_categories';
        $joinCol   = $by === 'tag' ? 'tag_id' : 'category_id';

        $stmt = $pdo->prepare(
            "SELECT DISTINCT p.*, COALESCE(NULLIF(u.display_name, ''), u.name) as author_name,
                    u.slug as author_slug, sm.og_image_id
             FROM posts p
             LEFT JOIN users u ON u.id = p.author_id
             LEFT JOIN seo_meta sm ON sm.target_type = p.post_type AND sm.target_id = p.id
             JOIN {$joinTable} jt1 ON jt1.post_id = p.id
             JOIN {$joinTable} jt2 ON jt2.{$joinCol} = jt1.{$joinCol} AND jt2.post_id = ?
             WHERE p.id != ? AND p.status = 'published'
             ORDER BY p.published_at DESC
             LIMIT ?"
        );
        $stmt->execute([$currentId, $currentId, $limit]);
        return \TypeDock\Content\PostView::projectList($stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function fetchSiteOptions(array $params): array
    {
        $keys = $params['keys'] ?? null;
        if (!is_array($keys) || $keys === []) {
            return [];
        }

        $pdo          = \Flight::db();
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $stmt         = $pdo->prepare("SELECT key_name, value FROM site_options WHERE key_name IN ($placeholders)");
        $stmt->execute(array_values($keys));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['key_name']] = json_decode((string) $row['value'], true);
        }
        return $out;
    }

    private function buildOrderBy(?string $sort, string $alias, string $default, string $direction): string
    {
        if ($sort === null || $sort === '') {
            if ($default === '') {
                return '';
            }
            return $alias . '.' . $default . ' ' . $direction;
        }
        $desc = false;
        if (str_starts_with($sort, '-')) {
            $desc = true;
            $sort = substr($sort, 1);
        }
        // Guard against SQL injection — only allow word characters.
        if (!preg_match('/^[A-Za-z0-9_]+$/', $sort)) {
            return $default === '' ? '1' : $alias . '.' . $default . ' ' . $direction;
        }
        return $alias . '.' . $sort . ' ' . ($desc ? 'DESC' : 'ASC');
    }

}
