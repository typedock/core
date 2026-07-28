<?php
declare(strict_types=1);

namespace TypeDock\Seo;

use TypeDock\Contract\MetaTagResolver;

class SeoService
{
    /** @var MetaTagResolver[] */
    private array $resolvers = [];

    public function __construct(private readonly \PDO $pdo) {}

    public function addResolver(MetaTagResolver $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * Get all meta tags for a target.
     *
     * @return array<string, string>
     */
    public function getMetaTags(string $targetType, string $targetId): array
    {
        // Get from DB
        $stmt = $this->pdo->prepare(
            'SELECT * FROM seo_meta WHERE target_type = ? AND target_id = ? LIMIT 1'
        );
        $stmt->execute([$targetType, $targetId]);
        $meta = $stmt->fetch();

        // Get global defaults
        $stmt = $this->pdo->prepare(
            "SELECT * FROM seo_meta WHERE target_type = 'global' AND target_id IS NULL LIMIT 1"
        );
        $stmt->execute();
        $global = $stmt->fetch();

        $tags = [];

        // Merge: specific overrides global
        foreach ([$global, $meta] as $source) {
            if ($source === false) {
                continue;
            }
            if (!empty($source['seo_title'])) {
                $tags['title'] = (string) $source['seo_title'];
            }
            if (!empty($source['meta_description'])) {
                $tags['description'] = (string) $source['meta_description'];
            }
            if (!empty($source['robots'])) {
                $tags['robots'] = (string) $source['robots'];
            }
            if (!empty($source['canonical_url'])) {
                $tags['canonical'] = (string) $source['canonical_url'];
            }
            if (!empty($source['og_title'])) {
                $tags['og:title'] = (string) $source['og_title'];
            }
            if (!empty($source['og_description'])) {
                $tags['og:description'] = (string) $source['og_description'];
            }
            if (!empty($source['twitter_card'])) {
                $tags['twitter:card'] = (string) $source['twitter_card'];
            }
        }

        // Run additional resolvers (modules)
        foreach ($this->resolvers as $resolver) {
            $extra = $resolver->resolve($targetType, $targetId);
            $tags  = array_merge($tags, $extra);
        }

        return $tags;
    }

    /**
     * Load the raw per-target SEO row (no merge with global defaults).
     *
     * Used by the admin edit form: empty fields there mean "inherit from
     * global", so we must NOT pre-fill them with the global defaults —
     * otherwise saving the form would copy globals into per-target rows and
     * the inheritance chain would silently lock to whatever global said at
     * that moment. getMetaTags() is the right tool for render-time output;
     * this is the right tool for editing.
     *
     * @return array<string, mixed>|null
     */
    public function findByTarget(string $targetType, ?string $targetId): ?array
    {
        if ($targetId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM seo_meta WHERE target_type = ? AND target_id IS NULL LIMIT 1'
            );
            $stmt->execute([$targetType]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM seo_meta WHERE target_type = ? AND target_id = ? LIMIT 1'
            );
            $stmt->execute([$targetType, $targetId]);
        }
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Get or create SEO meta for saving.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function upsert(string $targetType, ?string $targetId, array $data): array
    {
        // Branch rather than `(target_id = ? OR (target_id IS NULL AND ? IS NULL))`:
        // PostgreSQL cannot infer a bare parameter's type from `? IS NULL` and
        // refuses to prepare the statement at all, whatever the value. This is
        // the same shape findByTarget() already uses.
        if ($targetId === null) {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM seo_meta WHERE target_type = ? AND target_id IS NULL LIMIT 1'
            );
            $stmt->execute([$targetType]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT id FROM seo_meta WHERE target_type = ? AND target_id = ? LIMIT 1'
            );
            $stmt->execute([$targetType, $targetId]);
        }
        $existing = $stmt->fetch();
        $now      = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($existing !== false) {
            $id = (string) $existing['id'];
            $this->pdo->prepare(
                'UPDATE seo_meta SET seo_title = ?, meta_description = ?, canonical_url = ?, robots = ?,
                 og_title = ?, og_description = ?, og_image_id = ?, twitter_card = ?,
                 focus_keyword = ?, schema_type = ?, updated_at = ?
                 WHERE id = ?'
            )->execute([
                $data['seo_title'] ?? null,
                $data['meta_description'] ?? null,
                $data['canonical_url'] ?? null,
                $data['robots'] ?? null,
                $data['og_title'] ?? null,
                $data['og_description'] ?? null,
                $data['og_image_id'] ?? null,
                $data['twitter_card'] ?? null,
                $data['focus_keyword'] ?? null,
                $data['schema_type'] ?? null,
                $now,
                $id,
            ]);
        } else {
            $id = typedock_uuid7();
            $this->pdo->prepare(
                'INSERT INTO seo_meta (id, target_type, target_id, seo_title, meta_description,
                 canonical_url, robots, og_title, og_description, og_image_id, twitter_card,
                 focus_keyword, schema_type, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id, $targetType, $targetId,
                $data['seo_title'] ?? null,
                $data['meta_description'] ?? null,
                $data['canonical_url'] ?? null,
                $data['robots'] ?? null,
                $data['og_title'] ?? null,
                $data['og_description'] ?? null,
                $data['og_image_id'] ?? null,
                $data['twitter_card'] ?? null,
                $data['focus_keyword'] ?? null,
                $data['schema_type'] ?? null,
                $now, $now,
            ]);
        }

        $stmt = $this->pdo->prepare('SELECT * FROM seo_meta WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: [];
    }

    /**
     * Build the full SEO context for a rendered page: merged meta values,
     * OG image URL resolution, JSON-LD, and sensible fallbacks from the
     * page row itself.
     *
     * Precedence (highest wins):
     *   1. Per-page seo_meta row (explicit editor input)
     *   2. First image in the Tiptap body (for page-specific social cards)
     *   3. Global seo_meta row (site defaults from /admin/settings/seo)
     *   4. Derived from page row (title/excerpt/slug)
     *
     * Returned as a plain object so themes can do `{$seo->ogImageUrl}`
     * without caring about DB shape.
     *
     * @param array<string, mixed> $page  Page/post row (must include id,
     *     post_type, title, slug; excerpt/published_at/updated_at/author_name
     *     are used when present)
     */
    public function resolveForPage(array $page): object
    {
        $type = (string) ($page['post_type'] ?? 'page');
        $id   = (string) ($page['id'] ?? '');
        $raw  = $this->findByTarget($type, $id) ?? [];
        $global = $this->findByTarget('global', null) ?? [];

        $pick = static function (string $key) use ($raw, $global): ?string {
            $v = $raw[$key] ?? null;
            if ($v !== null && $v !== '') {
                return (string) $v;
            }
            $v = $global[$key] ?? null;
            return ($v !== null && $v !== '') ? (string) $v : null;
        };

        $title       = $pick('seo_title')        ?? (string) ($page['title'] ?? '');
        $description = $pick('meta_description')
            ?? (trim((string) ($page['excerpt'] ?? '')) !== ''
                ? (string) $page['excerpt']
                : \TypeDock\Content\PostService::excerptFromRow($page));
        $ogTitle     = $pick('og_title')         ?? $title;
        $ogDesc      = $pick('og_description')   ?? $description;
        $twitterCard = $pick('twitter_card')     ?? 'summary_large_image';
        $robots      = $pick('robots');
        $canonical   = $pick('canonical_url')    ?? $this->defaultCanonical($page);
        $ogImageUrl  = $this->resolvePageOgImageUrl($page, $raw, $global);
        $schemaType  = $pick('schema_type');

        return (object) [
            'title'          => $title,
            'description'    => $description,
            'canonical'      => $canonical,
            'robots'         => $robots,
            'ogType'         => $type === 'post' ? 'article' : 'website',
            'ogTitle'        => $ogTitle,
            'ogDescription'  => $ogDesc,
            'ogImageUrl'     => $ogImageUrl,
            'twitterCard'    => $twitterCard,
            'schemaType'     => $schemaType,
            'jsonLd'         => $this->generateJsonLd($page, $schemaType),
        ];
    }

    public function resolveForHome(string $fallbackTitle, string $fallbackDescription = ''): object
    {
        $global = $this->findByTarget('global', null) ?? [];
        $pick = static function (string $key) use ($global): ?string {
            $v = $global[$key] ?? null;
            return ($v !== null && $v !== '') ? (string) $v : null;
        };

        $title       = $pick('seo_title')        ?? $fallbackTitle;
        $description = $pick('meta_description') ?? $fallbackDescription;
        $ogTitle     = $pick('og_title')         ?? $title;
        $ogDesc      = $pick('og_description')   ?? $description;
        $canonical   = rtrim((string) config('app.url', ''), '/') . '/';
        $ogImageUrl  = $this->resolveOgImageUrl(isset($global['og_image_id']) ? (string) $global['og_image_id'] : null);

        return (object) [
            'title'          => $title,
            'description'    => $description,
            'canonical'      => $canonical,
            'robots'         => $pick('robots'),
            'ogType'         => 'website',
            'ogTitle'        => $ogTitle,
            'ogDescription'  => $ogDesc,
            'ogImageUrl'     => $ogImageUrl,
            'twitterCard'    => $pick('twitter_card') ?? 'summary_large_image',
            'schemaType'     => null,
            'jsonLd'         => '',
        ];
    }

    private function defaultCanonical(array $page): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        $slug = ltrim((string) ($page['slug'] ?? ''), '/');
        $prefix = ($page['post_type'] ?? '') === 'post' ? post_path() . '/' : '/';
        return $base . $prefix . $slug;
    }

    public function resolveOgImageUrl(?string $mediaId): ?string
    {
        return $this->resolveOgImage($mediaId)['url'] ?? null;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $global
     */
    private function resolvePageOgImageUrl(array $page, array $raw, array $global): ?string
    {
        $explicitId = $raw['og_image_id'] ?? null;
        $url = $this->resolveOgImageUrl(is_string($explicitId) ? $explicitId : null);
        if ($url !== null) {
            return $url;
        }

        $url = $this->firstTiptapImageUrl($page['body'] ?? null);
        if ($url !== null) {
            return $url;
        }

        $globalId = $global['og_image_id'] ?? null;
        return $this->resolveOgImageUrl(is_string($globalId) ? $globalId : null);
    }

    private function firstTiptapImageUrl(mixed $body): ?string
    {
        if (!is_string($body) || trim($body) === '') {
            return null;
        }

        try {
            $doc = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($doc)) {
            return null;
        }

        return $this->findFirstImageUrlInNode($doc);
    }

    /**
     * @param array<string, mixed> $node
     */
    private function findFirstImageUrlInNode(array $node): ?string
    {
        if (($node['type'] ?? null) === 'image' && is_array($node['attrs'] ?? null)) {
            $attrs = $node['attrs'];
            $mediaId = $attrs['mediaId'] ?? null;
            $resolved = $this->resolveOgImageUrl(is_string($mediaId) ? $mediaId : null);
            if ($resolved !== null) {
                return $resolved;
            }

            $src = $attrs['src'] ?? null;
            return is_string($src) ? $this->absoluteImageUrl($src) : null;
        }

        if (!is_array($node['content'] ?? null)) {
            return null;
        }

        foreach ($node['content'] as $child) {
            if (!is_array($child)) {
                continue;
            }
            $url = $this->findFirstImageUrlInNode($child);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function absoluteImageUrl(string $src): ?string
    {
        $src = trim($src);
        if ($src === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }

        if (str_starts_with($src, '//')) {
            return 'https:' . $src;
        }

        if (str_starts_with($src, '/')) {
            return rtrim((string) config('app.url', ''), '/') . $src;
        }

        return null;
    }

    /**
     * Resolve a media id into the public URL plus alt text. Themes consume
     * this through PostView (`$post->thumbnail` + `$post->thumbnailAlt`);
     * SEO meta consumers can use the URL-only `resolveOgImageUrl()` shortcut.
     *
     * @return array{url: string, alt: string}|null
     */
    public function resolveOgImage(?string $mediaId): ?array
    {
        if ($mediaId === null || $mediaId === '') {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT path, alt_text FROM media WHERE id = ? LIMIT 1');
        $stmt->execute([$mediaId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $path = (string) ($row['path'] ?? '');
        if ($path === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $path)) {
            $url = $path;
        } else {
            try {
                $url = \Flight::storage()->url($path);
            } catch (\Throwable) {
                $url = rtrim((string) config('app.url', ''), '/') . '/uploads/' . ltrim($path, '/');
            }
        }

        return ['url' => $url, 'alt' => (string) ($row['alt_text'] ?? '')];
    }

    /**
     * Generate JSON-LD structured data for a page.
     *
     * @param  array<string, mixed> $page
     * @param  string|null          $schemaType Editor-selected Schema.org type;
     *     overrides the default mapping (post→BlogPosting, page→WebPage).
     * @return string JSON-LD script tag
     */
    public function generateJsonLd(array $page, ?string $schemaType = null): string
    {
        $siteUrl  = rtrim((string) config('app.url', ''), '/');
        $siteName = config('app.name', 'TypeDock');

        $type = $schemaType ?: (($page['post_type'] ?? null) === 'post' ? 'BlogPosting' : 'WebPage');
        $prefix = ($page['post_type'] ?? null) === 'post' ? post_path() . '/' : '/';

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => $type,
            'headline' => $page['title'] ?? '',
            'url'      => $siteUrl . $prefix . ltrim((string) ($page['slug'] ?? ''), '/'),
            'publisher' => [
                '@type' => 'Organization',
                'name'  => $siteName,
            ],
        ];

        $excerpt = trim((string) ($page['excerpt'] ?? '')) !== ''
            ? (string) $page['excerpt']
            : \TypeDock\Content\PostService::excerptFromRow($page);
        if ($excerpt !== '') {
            $data['description'] = $excerpt;
        }
        if (!empty($page['published_at'])) {
            $data['datePublished'] = $page['published_at'];
        }
        if (!empty($page['updated_at'])) {
            $data['dateModified'] = $page['updated_at'];
        }
        if (!empty($page['author_name'])) {
            $data['author'] = ['@type' => 'Person', 'name' => $page['author_name']];
        }

        return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
}
