<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Content\PostService;
use TypeDock\Search\LikeSearchEngine;
use TypeDock\Seo\RssGenerator;

final class ScheduledPostTest extends TestCase
{
    private \PDO $pdo;
    private PostService $postService;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE posts (
            id TEXT NOT NULL PRIMARY KEY,
            slug TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NULL,
            body_markdown TEXT NULL,
            excerpt TEXT NULL,
            post_type TEXT NOT NULL,
            status TEXT NOT NULL,
            author_id TEXT NULL,
            locale TEXT NOT NULL,
            published_at TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE users (
            id TEXT NOT NULL PRIMARY KEY,
            name TEXT NOT NULL,
            display_name TEXT NULL,
            slug TEXT NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE seo_meta (
            target_type TEXT NOT NULL,
            target_id TEXT NOT NULL,
            og_image_id TEXT NULL,
            PRIMARY KEY (target_type, target_id)
        )');

        $this->pdo->exec('CREATE TABLE site_options (
            key_name TEXT NOT NULL PRIMARY KEY,
            value TEXT NOT NULL,
            group_name TEXT NOT NULL
        )');

        $this->postService = new PostService($this->pdo);
    }

    public function testComputeDisplayStatus(): void
    {
        $now = '2026-08-23 12:00:00';

        $this->assertSame('draft', PostService::computeDisplayStatus('draft', null, $now));
        $this->assertSame('review', PostService::computeDisplayStatus('review', null, $now));
        $this->assertSame('scheduled', PostService::computeDisplayStatus('scheduled', null, $now));
        $this->assertSame('published', PostService::computeDisplayStatus('published', null, $now));
        $this->assertSame('published', PostService::computeDisplayStatus('published', '2026-08-20 10:00:00', $now));
        $this->assertSame('scheduled', PostService::computeDisplayStatus('published', '2026-08-25 10:00:00', $now));
    }

    public function testPostServiceListPublishedExcludesFuturePostsByDefault(): void
    {
        $this->pdo->exec(
            "INSERT INTO posts (id, slug, title, post_type, status, locale, published_at, created_at, updated_at) VALUES
                ('post-1', 'live-post', 'Live Post', 'post', 'published', 'en', '2026-08-20 10:00:00', '2026-08-20 10:00:00', '2026-08-20 10:00:00'),
                ('post-2', 'future-post', 'Future Post', 'post', 'published', 'en', '2026-08-30 10:00:00', '2026-08-20 10:00:00', '2026-08-20 10:00:00')"
        );

        $result = $this->postService->list([
            'post_type' => 'post',
            'status'    => 'published',
            'now'       => '2026-08-23 12:00:00',
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('live-post', $result['items'][0]['slug']);
        $this->assertSame('published', $result['items'][0]['display_status']);
    }

    public function testPostServiceListScheduledIncludesFuturePublishedPosts(): void
    {
        $this->pdo->exec(
            "INSERT INTO posts (id, slug, title, post_type, status, locale, published_at, created_at, updated_at) VALUES
                ('post-1', 'live-post', 'Live Post', 'post', 'published', 'en', '2026-08-20 10:00:00', '2026-08-20 10:00:00', '2026-08-20 10:00:00'),
                ('post-2', 'future-post', 'Future Post', 'post', 'published', 'en', '2026-08-30 10:00:00', '2026-08-20 10:00:00', '2026-08-20 10:00:00')"
        );

        $result = $this->postService->list([
            'post_type' => 'post',
            'status'    => 'scheduled',
            'now'       => '2026-08-23 12:00:00',
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame('future-post', $result['items'][0]['slug']);
        $this->assertSame('scheduled', $result['items'][0]['display_status']);
    }

    public function testLikeSearchEngineExcludesFuturePosts(): void
    {
        $this->pdo->exec(
            "INSERT INTO posts (id, slug, title, body_markdown, excerpt, post_type, status, locale, published_at, created_at, updated_at) VALUES
                ('post-1', 'live-ai', 'AI in 2026', 'AI is growing', '', 'post', 'published', 'en', '2026-08-20 10:00:00', '2026-08-20 10:00:00', '2026-08-20 10:00:00'),
                ('post-2', 'future-ai', 'AI in 2030', 'AI is advanced', '', 'post', 'published', 'en', '2099-01-01 10:00:00', '2026-08-20 10:00:00', '2026-08-20 10:00:00')"
        );

        $search = new LikeSearchEngine($this->pdo);
        $results = $search->search('AI');

        $this->assertSame(1, $results['total']);
        $this->assertSame('live-ai', $results['items'][0]['slug']);
    }

    public function testRssGeneratorExcludesFuturePosts(): void
    {
        $this->pdo->exec(
            "INSERT INTO posts (id, slug, title, excerpt, post_type, status, locale, published_at, created_at, updated_at) VALUES
                ('post-1', 'live-feed', 'Feed Post', 'Feed excerpt', 'post', 'published', 'en', '2026-08-20 10:00:00', '2026-08-20 10:00:00', '2026-08-20 10:00:00'),
                ('post-2', 'future-feed', 'Future Feed', 'Future excerpt', 'post', 'published', 'en', '2099-01-01 10:00:00', '2026-08-20 10:00:00', '2026-08-20 10:00:00')"
        );

        $rss = new RssGenerator($this->pdo, 'http://localhost', 'TypeDock Site');
        $xml = $rss->generate();

        $this->assertStringContainsString('live-feed', $xml);
        $this->assertStringNotContainsString('future-feed', $xml);
    }
}
