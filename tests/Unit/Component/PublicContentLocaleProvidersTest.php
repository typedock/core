<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Component;

use flight\Engine;
use PHPUnit\Framework\TestCase;
use TypeDock\Component\Provider\LatestPostsProvider;
use TypeDock\Component\Provider\MenuProvider;
use TypeDock\Component\RenderContext;

final class PublicContentLocaleProvidersTest extends TestCase
{
    protected function setUp(): void
    {
        \Flight::setEngine(new Engine());
    }

    public function testMenuProviderUsesRenderContextLocaleAndFallsBackToLegacyEnglish(): void
    {
        $pdo = $this->makeDatabase();
        $this->seedMenu($pdo, 'header', 'en', 'Home EN');
        $this->seedMenu($pdo, 'header', 'ja', 'Home JA');
        \Flight::map('db', static fn (): \PDO => $pdo);

        $provider = new MenuProvider();

        $ja = $provider->resolve(['location' => 'header'], new RenderContext(locale: 'ja'));
        self::assertSame('Home JA', $ja['items'][0]['label']);

        $fallback = $provider->resolve(['location' => 'footer'], new RenderContext(locale: 'ja'));
        self::assertSame([], $fallback['items']);

        $this->seedMenu($pdo, 'footer', 'en', 'Footer EN');
        $fallback = $provider->resolve(['location' => 'footer'], new RenderContext(locale: 'ja'));
        self::assertSame('Footer EN', $fallback['items'][0]['label']);
    }

    public function testLatestPostsProviderFiltersByRenderContextLocale(): void
    {
        $pdo = $this->makeDatabase();
        $this->seedPost($pdo, 'post-en', 'Hello EN', 'en');
        $this->seedPost($pdo, 'post-ja', 'Hello JA', 'ja');
        \Flight::map('db', static fn (): \PDO => $pdo);

        $data = (new LatestPostsProvider())->resolve(['count' => 10], new RenderContext(locale: 'ja'));

        self::assertCount(1, $data['posts']);
        self::assertSame('Hello JA', $data['posts'][0]->title);
    }

    private function makeDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE menus (
                id TEXT PRIMARY KEY,
                name TEXT,
                location TEXT,
                locale TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE menu_items (
                id TEXT PRIMARY KEY,
                menu_id TEXT,
                parent_id TEXT NULL,
                label TEXT,
                url TEXT NULL,
                target_type TEXT NULL,
                target_id TEXT NULL,
                css_class TEXT NULL,
                sort_order INTEGER,
                created_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE posts (
                id TEXT PRIMARY KEY,
                slug TEXT,
                title TEXT,
                body TEXT NULL,
                excerpt TEXT NULL,
                post_type TEXT,
                status TEXT,
                locale TEXT,
                author_id TEXT NULL,
                published_at TEXT,
                updated_at TEXT
            )'
        );
        $pdo->exec('CREATE TABLE users (id TEXT PRIMARY KEY, name TEXT, display_name TEXT NULL, slug TEXT NULL)');
        $pdo->exec('CREATE TABLE seo_meta (id TEXT NULL, target_type TEXT, target_id TEXT NULL, og_image_id TEXT NULL)');
        $pdo->exec('CREATE TABLE media (id TEXT PRIMARY KEY, path TEXT, alt_text TEXT NULL)');
        $pdo->exec('CREATE TABLE categories (id TEXT PRIMARY KEY, name TEXT, slug TEXT, sort_order INTEGER)');
        $pdo->exec('CREATE TABLE post_categories (post_id TEXT, category_id TEXT)');

        return $pdo;
    }

    private function seedMenu(\PDO $pdo, string $location, string $locale, string $label): void
    {
        $menuId = $location . '-' . $locale;
        $pdo->prepare(
            'INSERT INTO menus (id, name, location, locale, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$menuId, $label, $location, $locale, '2026-07-01 00:00:00', '2026-07-01 00:00:00']);
        $pdo->prepare(
            'INSERT INTO menu_items (id, menu_id, parent_id, label, url, target_type, target_id, css_class, sort_order, created_at)
             VALUES (?, ?, NULL, ?, ?, ?, NULL, NULL, 0, ?)'
        )->execute([$menuId . '-item', $menuId, $label, '/' . $locale, 'custom', '2026-07-01 00:00:00']);
    }

    private function seedPost(\PDO $pdo, string $id, string $title, string $locale): void
    {
        $pdo->prepare(
            "INSERT INTO posts (id, slug, title, body, excerpt, post_type, status, locale, author_id, published_at, updated_at)
             VALUES (?, ?, ?, NULL, NULL, 'post', 'published', ?, NULL, ?, ?)"
        )->execute([$id, $id, $title, $locale, '2026-07-01 00:00:00', '2026-07-01 00:00:00']);
    }
}
