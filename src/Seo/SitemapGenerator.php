<?php
declare(strict_types=1);

namespace TypeDock\Seo;

class SitemapGenerator
{
    private string $siteUrl;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->siteUrl = rtrim((string) config('app.url', 'http://localhost'), '/');
    }

    /**
     * Generate sitemap index XML.
     */
    public function generateIndex(): string
    {
        $sitemaps = [
            $this->siteUrl . '/sitemap-pages.xml',
            $this->siteUrl . '/sitemap-posts.xml',
            $this->siteUrl . '/sitemap-categories.xml',
        ];

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($sitemaps as $url) {
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . htmlspecialchars($url, ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
            $xml .= '    <lastmod>' . date('Y-m-d') . "</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }
        $xml .= '</sitemapindex>';

        return $xml;
    }

    /**
     * Generate pages sitemap.
     */
    public function generatePages(): string
    {
        return $this->buildUrlset($this->publishedRows('page'), function (array $page): array {
            return [
                'loc'        => $this->siteUrl . slug_path((string) $page['slug']),
                'lastmod'    => substr((string) ($page['updated_at'] ?? date('Y-m-d')), 0, 10),
                'changefreq' => 'monthly',
                'priority'   => '0.7',
            ];
        });
    }

    /**
     * Generate posts sitemap.
     *
     * Posts sit under the configured archive segment, so their URLs go through
     * the same helper the router, canonical tags and the import link rewriter
     * use. Building them like pages advertised `/{slug}`, which 404s on any
     * site that is not using the default archive slug.
     */
    public function generatePosts(): string
    {
        return $this->buildUrlset($this->publishedRows('post'), function (array $post): array {
            return [
                'loc'        => $this->siteUrl . post_path((string) $post['slug']),
                'lastmod'    => substr((string) ($post['updated_at'] ?? date('Y-m-d')), 0, 10),
                'changefreq' => 'monthly',
                'priority'   => '0.7',
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    private function publishedRows(string $postType): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT slug, updated_at, published_at FROM posts
             WHERE post_type = ? AND status = 'published'
             ORDER BY updated_at DESC LIMIT 1000"
        );
        $stmt->execute([$postType]);

        return $stmt->fetchAll();
    }

    /**
     * Generate categories sitemap.
     */
    public function generateCategories(): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug, created_at FROM categories ORDER BY created_at DESC LIMIT 500'
        );
        $stmt->execute();
        $categories = $stmt->fetchAll();

        return $this->buildUrlset($categories, function (array $cat): array {
            return [
                'loc'        => $this->siteUrl . '/category' . slug_path((string) $cat['slug']),
                'lastmod'    => substr((string) ($cat['created_at'] ?? date('Y-m-d')), 0, 10),
                'changefreq' => 'weekly',
                'priority'   => '0.5',
            ];
        });
    }

    /**
     * @param array<array<string, mixed>> $items
     * @param callable(array<string, mixed>): array<string, string> $urlBuilder
     */
    private function buildUrlset(array $items, callable $urlBuilder): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($items as $item) {
            $url  = $urlBuilder($item);
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars((string) $url['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</loc>\n";
            if (isset($url['lastmod'])) {
                $xml .= '    <lastmod>' . htmlspecialchars($url['lastmod'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</lastmod>\n";
            }
            if (isset($url['changefreq'])) {
                $xml .= '    <changefreq>' . $url['changefreq'] . "</changefreq>\n";
            }
            if (isset($url['priority'])) {
                $xml .= '    <priority>' . $url['priority'] . "</priority>\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';
        return $xml;
    }
}
