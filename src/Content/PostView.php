<?php
declare(strict_types=1);

namespace TypeDock\Content;

use TypeDock\Seo\SeoService;

/**
 * Canonical view-model projection for posts and pages exposed to themes.
 *
 * Every channel that hands a post-like row to a theme — route-provided
 * archive lists, search results, the {component(...)} provider output,
 * and the theme.json `templates.*.fetch` resolver — projects through this
 * class so themes only need to learn one shape:
 *
 *   $post->title, $post->url, $post->thumbnail, $post->publishedAt,
 *   $post->author->name, $post->category?->name
 *
 * For the `single`/`page` context, projectSingle() returns a superset
 * (`$page->renderedBody`, `$page->categories`, `$page->tags`,
 * `$page->author->avatar`, `$page->ogImageUrl`).
 *
 * Themes MUST consume the documented properties only — the array-shape
 * `$post['title']` access used by older themes is not supported anymore.
 */
class PostView
{
    /**
     * Project a list of pages-rows into the public list shape.
     *
     * Eager-loads each row's primary category and the global OG-image
     * fallback in a single batch so callers don't fan out N+1 queries.
     *
     * Rows must include at minimum `id`, `slug`, `title`, `published_at`,
     * `post_type`, plus the joined `author_name`, `author_slug`, and
     * `og_image_id` columns.
     *
     * @param  array<array<string, mixed>> $rows
     * @return array<object>
     */
    public static function projectList(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $seo = new SeoService(\Flight::db());
        $global = $seo->findByTarget('global', null) ?? [];
        $globalImage = $seo->resolveOgImage(
            isset($global['og_image_id']) ? (string) $global['og_image_id'] : null
        );

        $categoriesByPage = self::primaryCategoryByPageId(
            array_values(array_filter(array_map(static fn ($r) => $r['id'] ?? null, $rows)))
        );

        $out = [];
        foreach ($rows as $row) {
            $pageId = (string) ($row['id'] ?? '');
            $out[] = self::buildListView($row, $seo, $globalImage, $categoriesByPage[$pageId] ?? null);
        }
        return $out;
    }

    /**
     * Project a single row (typically the result of fetchPosts /
     * RelatedPosts) where the caller has already resolved the global
     * fallback. Used by FetchResolver to avoid re-running the global
     * lookup once per row.
     *
     * @param array<string, mixed>                   $row
     * @param array{url: string, alt: string}|null   $globalImage
     */
    public static function projectOne(
        array $row,
        ?SeoService $seo = null,
        ?array $globalImage = null,
        ?object $primaryCategory = null
    ): object {
        $seo ??= new SeoService(\Flight::db());
        return self::buildListView($row, $seo, $globalImage, $primaryCategory);
    }

    /**
     * Build the single-page view model used by `single.latte` /
     * `page.latte`. Adds renderedBody, categories, tags, an enriched
     * author (with avatar + bio + websiteUrl), and ogImageUrl to the
     * list shape.
     *
     * @param array<string, mixed>        $row
     * @param array<array<string, mixed>> $categories
     * @param array<array<string, mixed>> $tags
     */
    public static function projectSingle(
        array $row,
        string $renderedBody,
        array $categories,
        array $tags
    ): object {
        $seo = new SeoService(\Flight::db());
        $global = $seo->findByTarget('global', null) ?? [];
        $globalImage = $seo->resolveOgImage(
            isset($global['og_image_id']) ? (string) $global['og_image_id'] : null
        );

        $primaryCategory = $categories === [] ? null : (object) [
            'name' => (string) $categories[0]['name'],
            'slug' => (string) $categories[0]['slug'],
        ];

        $base = self::buildListView($row, $seo, $globalImage, $primaryCategory);
        $author = self::loadAuthorDetails((string) ($row['author_id'] ?? ''));

        // `excerpt` keeps the auto-generated fallback so SEO description /
        // <meta> tags always have something. `lede` is the operator-set
        // excerpt only — empty unless explicitly authored — so single layouts
        // can show a kicker under the title without duplicating the article's
        // first sentence on every post.
        $base->lede = isset($row['excerpt']) && trim((string) $row['excerpt']) !== ''
            ? (string) $row['excerpt']
            : '';
        $base->renderedBody = $renderedBody;
        $base->status       = (string) ($row['status'] ?? '');
        $base->categories   = self::projectTerms($categories);
        $base->tags         = self::projectTerms($tags);
        $base->author       = $author ?? (object) [
            'name'       => $row['author_name'] ?? null,
            'slug'       => $row['author_slug'] ?? null,
            'avatar'     => null,
            'bio'        => null,
            'websiteUrl' => null,
        ];
        $base->ogImageUrl   = $base->thumbnail;

        return $base;
    }

    /**
     * @param array<string, mixed>                  $row
     * @param array{url: string, alt: string}|null  $globalImage
     */
    private static function buildListView(
        array $row,
        SeoService $seo,
        ?array $globalImage,
        ?object $primaryCategory
    ): object {
        $base    = rtrim((string) config('app.url', ''), '/');
        $isPost  = ($row['post_type'] ?? '') === 'post';
        $rowSlug = (string) ($row['slug'] ?? '');
        // Percent-encoded here rather than in the column: themes drop this
        // straight into an href, and a slug may be in any script.
        $path    = $isPost ? post_path($rowSlug) : slug_path($rowSlug);

        $perRowOgImageId = !empty($row['og_image_id']) ? (string) $row['og_image_id'] : null;
        $image = $perRowOgImageId !== null
            ? $seo->resolveOgImage($perRowOgImageId)
            : $globalImage;
        $thumbnail = $image['url'] ?? null;
        $thumbnailAlt = $image['alt'] ?? '';

        $excerpt = isset($row['excerpt']) && $row['excerpt'] !== ''
            ? (string) $row['excerpt']
            : PostService::excerptFromRow($row);

        return (object) [
            'id'           => (string) ($row['id'] ?? ''),
            'slug'         => (string) ($row['slug'] ?? ''),
            'title'        => (string) ($row['title'] ?? ''),
            'url'          => $base . $path,
            'excerpt'      => $excerpt,
            'publishedAt'  => $row['published_at'] ?? null,
            'updatedAt'    => $row['updated_at'] ?? null,
            'postType'     => $row['post_type'] ?? null,
            'thumbnail'    => $thumbnail,
            'heroImage'    => $thumbnail,
            'thumbnailAlt' => $thumbnailAlt,
            'author'       => (object) [
                'name' => $row['author_name'] ?? null,
                'slug' => $row['author_slug'] ?? null,
            ],
            'category'     => $primaryCategory,
        ];
    }

    /**
     * Batch-resolve the primary category for a list of page ids. The
     * primary category is the one with the lowest sort_order (then name)
     * — matching the order PostService::getCategories() uses.
     *
     * @param  array<string>          $pageIds
     * @return array<string, object>  keyed by post_id, value = (object){ name, slug }
     */
    private static function primaryCategoryByPageId(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map('strval', $pageIds))));
        if ($pageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = \Flight::db()->prepare(
            "SELECT pc.post_id, c.name, c.slug, c.sort_order
             FROM post_categories pc
             JOIN categories c ON c.id = pc.category_id
             WHERE pc.post_id IN ($placeholders)
             ORDER BY c.sort_order, c.name"
        );
        $stmt->execute($pageIds);

        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $pid = (string) $r['post_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = (object) ['name' => (string) $r['name'], 'slug' => (string) $r['slug']];
            }
        }
        return $out;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @return array<object>
     */
    private static function projectTerms(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = (object) [
                'id'   => (string) ($r['id'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
                'slug' => (string) ($r['slug'] ?? ''),
            ];
        }
        return $out;
    }

    private static function loadAuthorDetails(string $authorId): ?object
    {
        if ($authorId === '') {
            return null;
        }
        $stmt = \Flight::db()->prepare(
            'SELECT u.name, u.display_name, u.slug, u.bio, u.website_url, u.avatar_path,
                    m.path AS avatar_media_path
             FROM users u
             LEFT JOIN media m ON m.id = u.avatar_media_id
             WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$authorId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $avatar = null;
        if (!empty($row['avatar_media_path'])) {
            try {
                $avatar = \Flight::storage()->url((string) $row['avatar_media_path']);
            } catch (\Throwable) {
                $avatar = null;
            }
        } elseif (!empty($row['avatar_path'])) {
            $avatar = (string) $row['avatar_path'];
        }

        $name = $row['display_name'] !== null && $row['display_name'] !== ''
            ? (string) $row['display_name']
            : (string) $row['name'];

        return (object) [
            'name'       => $name,
            'slug'       => $row['slug'] ?? null,
            'avatar'     => $avatar,
            'bio'        => $row['bio'] ?? null,
            'websiteUrl' => $row['website_url'] ?? null,
        ];
    }
}
