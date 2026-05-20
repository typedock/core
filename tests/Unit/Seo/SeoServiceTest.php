<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Seo;

use flight\Engine;
use PHPUnit\Framework\TestCase;
use TypeDock\Contract\StorageDriver;
use TypeDock\Seo\SeoService;

final class SeoServiceTest extends TestCase
{
    private \PDO $pdo;
    private SeoService $service;

    protected function setUp(): void
    {
        \Flight::setEngine(new Engine());
        \Flight::map('storage', static fn (): StorageDriver => new class implements StorageDriver {
            public function put(string $path, string $contents): bool { return true; }
            public function putFile(string $path, string $localPath): bool { return true; }
            public function get(string $path): ?string { return null; }
            public function exists(string $path): bool { return false; }
            public function delete(string $path): bool { return true; }
            public function url(string $path): string { return 'https://cdn.example/' . ltrim($path, '/'); }
            public function listFiles(string $directory): array { return []; }
        });

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE media (
                id TEXT PRIMARY KEY,
                path TEXT NOT NULL,
                alt_text TEXT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE seo_meta (
                id TEXT PRIMARY KEY,
                target_type TEXT NOT NULL,
                target_id TEXT NULL,
                seo_title TEXT NULL,
                meta_description TEXT NULL,
                canonical_url TEXT NULL,
                robots TEXT NULL,
                og_title TEXT NULL,
                og_description TEXT NULL,
                og_image_id TEXT NULL,
                twitter_card TEXT NULL,
                focus_keyword TEXT NULL,
                schema_type TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $this->service = new SeoService($this->pdo);
    }

    public function testResolveForHomeUsesGlobalDefaults(): void
    {
        $this->pdo->prepare('INSERT INTO media (id, path) VALUES (?, ?)')->execute([
            'media-1',
            '2026/hero.jpg',
        ]);
        $this->service->upsert('global', null, [
            'seo_title' => 'Custom Home Title',
            'meta_description' => 'Custom home description',
            'robots' => 'index,follow',
            'og_title' => 'Custom OG Title',
            'og_description' => 'Custom OG description',
            'og_image_id' => 'media-1',
            'twitter_card' => 'summary',
        ]);

        $seo = $this->service->resolveForHome('Blog', 'Fallback description');

        $this->assertSame('Custom Home Title', $seo->title);
        $this->assertSame('Custom home description', $seo->description);
        $this->assertSame('Custom OG Title', $seo->ogTitle);
        $this->assertSame('Custom OG description', $seo->ogDescription);
        $this->assertSame('https://cdn.example/2026/hero.jpg', $seo->ogImageUrl);
        $this->assertSame('summary', $seo->twitterCard);
        $this->assertSame('website', $seo->ogType);
    }

    public function testResolveForHomeFallsBackWhenGlobalDefaultsAreEmpty(): void
    {
        $seo = $this->service->resolveForHome('Blog', 'Site description');

        $this->assertSame('Blog', $seo->title);
        $this->assertSame('Site description', $seo->description);
        $this->assertSame('Blog', $seo->ogTitle);
        $this->assertSame('Site description', $seo->ogDescription);
        $this->assertNull($seo->ogImageUrl);
        $this->assertSame('summary_large_image', $seo->twitterCard);
    }

    public function testResolveForPageUsesFirstBodyImageBeforeGlobalDefault(): void
    {
        $this->pdo->prepare('INSERT INTO media (id, path) VALUES (?, ?)')->execute([
            'global-image',
            '2026/global.jpg',
        ]);
        $this->service->upsert('global', null, [
            'og_image_id' => 'global-image',
        ]);

        $seo = $this->service->resolveForPage([
            'id' => 'post-1',
            'post_type' => 'post',
            'slug' => 'body-image',
            'title' => 'Body image',
            'body' => json_encode([
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Intro']]],
                    ['type' => 'image', 'attrs' => ['src' => '/uploads/2026/body.jpg']],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame('http://localhost/uploads/2026/body.jpg', $seo->ogImageUrl);
    }

    public function testResolveForPageUsesImageMediaIdWhenPresent(): void
    {
        $this->pdo->prepare('INSERT INTO media (id, path) VALUES (?, ?)')->execute([
            'body-media',
            '2026/body-media.jpg',
        ]);

        $seo = $this->service->resolveForPage([
            'id' => 'post-1',
            'post_type' => 'post',
            'slug' => 'body-media',
            'title' => 'Body media',
            'body' => json_encode([
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'image',
                        'attrs' => [
                            'src' => '/uploads/stale.jpg',
                            'mediaId' => 'body-media',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame('https://cdn.example/2026/body-media.jpg', $seo->ogImageUrl);
    }

    public function testResolveForPageKeepsExplicitOgImageAheadOfBodyImage(): void
    {
        $this->pdo->prepare('INSERT INTO media (id, path) VALUES (?, ?)')->execute([
            'explicit-image',
            '2026/explicit.jpg',
        ]);
        $this->service->upsert('post', 'post-1', [
            'og_image_id' => 'explicit-image',
        ]);

        $seo = $this->service->resolveForPage([
            'id' => 'post-1',
            'post_type' => 'post',
            'slug' => 'explicit',
            'title' => 'Explicit',
            'body' => json_encode([
                'type' => 'doc',
                'content' => [
                    ['type' => 'image', 'attrs' => ['src' => '/uploads/2026/body.jpg']],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame('https://cdn.example/2026/explicit.jpg', $seo->ogImageUrl);
    }
}
