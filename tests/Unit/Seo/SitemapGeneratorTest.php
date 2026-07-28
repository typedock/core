<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Seo;

use PHPUnit\Framework\TestCase;
use TypeDock\Seo\SitemapGenerator;

/**
 * A sitemap is a promise that these URLs resolve. Anything it lists that the
 * router does not serve is worse than an omission — it is a crawl budget spent
 * on 404s.
 */
final class SitemapGeneratorTest extends TestCase
{
    private \PDO $pdo;
    private SitemapGenerator $generator;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec(
            'CREATE TABLE posts (
                slug TEXT NOT NULL,
                post_type TEXT NOT NULL,
                status TEXT NOT NULL,
                updated_at TEXT NULL,
                published_at TEXT NULL
            )'
        );
        $this->pdo->exec(
            "INSERT INTO posts (slug, post_type, status, updated_at, published_at) VALUES
                ('example-post', 'post', 'published', '2024-01-02 10:00:00', '2024-01-01 10:00:00'),
                ('draft-post', 'post', 'draft', '2024-01-02 10:00:00', NULL),
                ('showcase/customer-a', 'page', 'published', '2024-01-03 10:00:00', '2024-01-03 10:00:00')"
        );

        $this->generator = new SitemapGenerator($this->pdo);
    }

    public function testPostUrlsGoThroughTheArchiveSlugHelper(): void
    {
        // Compared against post_path() rather than a literal, so the test
        // states the invariant that matters — the sitemap agrees with the
        // router — instead of hard-coding whichever archive slug is default.
        $this->assertStringContainsString(
            '<loc>' . $this->siteUrl() . post_path('example-post') . '</loc>',
            $this->generator->generatePosts()
        );
    }

    public function testPostUrlsAreNotBuiltLikePageUrls(): void
    {
        $this->assertStringNotContainsString(
            '<loc>' . $this->siteUrl() . '/example-post</loc>',
            $this->generator->generatePosts(),
            'A post is not served from the site root'
        );
    }

    public function testUnpublishedPostsAreNotListed(): void
    {
        $this->assertStringNotContainsString('draft-post', $this->generator->generatePosts());
    }

    public function testPageKeepsItsFullPath(): void
    {
        $this->assertStringContainsString(
            '<loc>' . $this->siteUrl() . '/showcase/customer-a</loc>',
            $this->generator->generatePages()
        );
    }

    public function testPagesSitemapDoesNotContainPosts(): void
    {
        $this->assertStringNotContainsString('example-post', $this->generator->generatePages());
    }

    public function testIndexOnlyLinksToSitemapsThatHaveARoute(): void
    {
        $index = $this->generator->generateIndex();

        // Router::registerSystemRoutes() registers exactly these three
        // alongside /sitemap.xml. Adding a fourth to the index without a route
        // is the bug this guards.
        foreach (['pages', 'posts', 'categories'] as $child) {
            $this->assertStringContainsString(
                '<loc>' . $this->siteUrl() . '/sitemap-' . $child . '.xml</loc>',
                $index
            );
        }

        $this->assertSame(3, substr_count($index, '<loc>'));
    }

    private function siteUrl(): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }
}
