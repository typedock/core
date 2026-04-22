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
        $stmt = $this->pdo->prepare(
            'SELECT id FROM seo_meta WHERE target_type = ? AND (target_id = ? OR (target_id IS NULL AND ? IS NULL)) LIMIT 1'
        );
        $stmt->execute([$targetType, $targetId, $targetId]);
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
            $id = \Ramsey\Uuid\Uuid::uuid7()->toString();
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
     *   2. Global seo_meta row (site defaults from /admin/settings/seo)
     *   3. Derived from page row (title/excerpt/slug)
     *
     * Returned as a plain object so themes can do `{$seo->ogImageUrl}`
     * without caring about DB shape.
     *
     * @param array<string, mixed> $page  Page/post row (must include id,
     *     page_type, title, slug; excerpt/published_at/updated_at/author_name
     *     are used when present)
     */
    public function resolveForPage(array $page): object
    {
        $type = (string) ($page['page_type'] ?? 'page');
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
        $description = $pick('meta_description') ?? (string) ($page['excerpt'] ?? '');
        $ogTitle     = $pick('og_title')         ?? $title;
        $ogDesc      = $pick('og_description')   ?? $description;
        $twitterCard = $pick('twitter_card')     ?? 'summary_large_image';
        $robots      = $pick('robots');
        $canonical   = $pick('canonical_url')    ?? $this->defaultCanonical($page);
        $ogImageId   = $raw['og_image_id'] ?? $global['og_image_id'] ?? null;
        $ogImageUrl  = $ogImageId ? $this->resolveOgImageUrl((string) $ogImageId) : null;
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

    private function defaultCanonical(array $page): string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        $slug = ltrim((string) ($page['slug'] ?? ''), '/');
        $prefix = ($page['page_type'] ?? '') === 'post' ? post_path() . '/' : '/';
        return $base . $prefix . $slug;
    }

    private function resolveOgImageUrl(string $mediaId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT path FROM media WHERE id = ? LIMIT 1');
        $stmt->execute([$mediaId]);
        $path = $stmt->fetchColumn();
        if ($path === false || $path === null || $path === '') {
            return null;
        }
        $path = (string) $path;
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $base = rtrim((string) config('app.url', ''), '/');
        return $base . '/' . ltrim($path, '/');
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

        $type = $schemaType ?: (($page['page_type'] ?? null) === 'post' ? 'BlogPosting' : 'WebPage');
        $prefix = ($page['page_type'] ?? null) === 'post' ? post_path() . '/' : '/';

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

        if (!empty($page['excerpt'])) {
            $data['description'] = $page['excerpt'];
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
