<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Content;

use PHPUnit\Framework\TestCase;
use TypeDock\Content\MenuTreeResolver;

final class MenuTreeResolverTest extends TestCase
{
    public function testResolvesMenuForRequestedLocale(): void
    {
        $pdo = $this->makeDatabase();
        $this->seedMenu($pdo, 'header', 'en', 'Home EN');
        $this->seedMenu($pdo, 'header', 'ja', 'Home JA');

        $items = (new MenuTreeResolver($pdo))->resolve('header', 'ja');

        self::assertCount(1, $items);
        self::assertSame('Home JA', $items[0]->label);
    }

    public function testFallsBackToLegacyEnglishMenuWhenRequestedLocaleIsMissing(): void
    {
        $pdo = $this->makeDatabase();
        $this->seedMenu($pdo, 'header', 'en', 'Home EN');

        $items = (new MenuTreeResolver($pdo))->resolve('header', 'ja');

        self::assertCount(1, $items);
        self::assertSame('Home EN', $items[0]->label);
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
}
