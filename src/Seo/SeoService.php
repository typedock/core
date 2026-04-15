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
     * Generate JSON-LD structured data for a page.
     *
     * @param  array<string, mixed> $page
     * @return string JSON-LD script tag
     */
    public function generateJsonLd(array $page): string
    {
        $siteUrl  = config('app.url', '');
        $siteName = config('app.name', 'TypeDock');

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => $page['page_type'] === 'post' ? 'BlogPosting' : 'WebPage',
            'headline' => $page['title'] ?? '',
            'url'      => $siteUrl . '/' . ltrim((string) ($page['slug'] ?? ''), '/'),
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
