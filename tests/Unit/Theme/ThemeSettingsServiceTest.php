<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Theme;

use PHPUnit\Framework\TestCase;
use TypeDock\Theme\ThemeLoader;
use TypeDock\Theme\ThemeSettingsService;
use TypeDock\Theme\ThemeStyleRenderer;

/**
 * Exercises the theme-settings round-trip end-to-end:
 *   schema → form submission (flat dotted keys) → persisted JSON → CSS vars.
 *
 * Uses an in-memory SQLite DB plus a stub ThemeLoader so the suite doesn't
 * need real themes on disk. That keeps the coverage focused on the service
 * logic (coercion, defaults, reset) and lets CSS output be asserted against
 * a known schema.
 */
final class ThemeSettingsServiceTest extends TestCase
{
    private \PDO $pdo;
    private StubThemeLoader $loader;
    private ThemeSettingsService $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE site_options (
                key_name   TEXT PRIMARY KEY,
                value      TEXT,
                group_name TEXT,
                updated_at TEXT
            )'
        );

        $this->loader = new StubThemeLoader([
            'name'     => 'Test Theme',
            'settings' => [
                'colors' => [
                    'label'  => 'Colors',
                    'fields' => [
                        'primary' => ['type' => 'color', 'label' => 'Primary', 'default' => '#2563eb'],
                        'accent'  => ['type' => 'color', 'label' => 'Accent',  'default' => '#f59e0b'],
                    ],
                ],
                'layout' => [
                    'label'  => 'Layout',
                    'fields' => [
                        'sidebar' => [
                            'type'    => 'select',
                            'label'   => 'Sidebar',
                            'options' => ['none' => 'None', 'right' => 'Right', 'left' => 'Left'],
                            'default' => 'right',
                        ],
                        'content_width' => [
                            'type'    => 'select',
                            'label'   => 'Width',
                            'options' => ['narrow' => 'Narrow', 'normal' => 'Normal', 'wide' => 'Wide'],
                            'default' => 'normal',
                        ],
                    ],
                ],
                'header' => [
                    'label'  => 'Header',
                    'fields' => [
                        'sticky' => ['type' => 'boolean', 'label' => 'Sticky', 'default' => true],
                    ],
                ],
            ],
        ]);

        $this->service = new ThemeSettingsService($this->pdo, $this->loader);
    }

    public function testSchemaExposesDefaultsWhenNoValuesPersisted(): void
    {
        $all = $this->service->all();

        $this->assertSame('#2563eb', $all['colors']['primary']);
        $this->assertSame('right', $all['layout']['sidebar']);
        $this->assertTrue($all['header']['sticky']);
    }

    public function testGetWithDotPath(): void
    {
        $this->assertSame('#f59e0b', $this->service->get('colors.accent'));
        $this->assertSame('fallback', $this->service->get('missing.key', 'fallback'));
    }

    public function testSaveAcceptsFlatKeysAndCoercesTypes(): void
    {
        $this->service->save([
            'colors.primary'       => '#ff0000',
            'layout.sidebar'       => 'left',
            'layout.content_width' => 'wide',
            // Boolean checkbox not posted => should coerce to false.
        ]);

        $values = $this->service->all();
        $this->assertSame('#ff0000', $values['colors']['primary']);
        $this->assertSame('left', $values['layout']['sidebar']);
        $this->assertFalse($values['header']['sticky']);
    }

    public function testInvalidSelectValueFallsBackToDefault(): void
    {
        $this->service->save(['layout.sidebar' => 'nonsense']);
        $this->assertSame('right', $this->service->get('layout.sidebar'));
    }

    public function testResetDropsStoredRow(): void
    {
        $this->service->save(['colors.primary' => '#abc123']);
        $this->service->reset();
        $this->assertSame('#2563eb', $this->service->get('colors.primary'));

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM site_options WHERE key_name = 'theme_settings'");
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testCssVariablesOutputUsesSlugifiedPropertyNames(): void
    {
        $this->service->save([
            'colors.primary'       => '#112233',
            'layout.content_width' => 'wide',
        ]);

        $css = (new ThemeStyleRenderer($this->service))->renderCssVariables();

        $this->assertStringContainsString('--td-colors-primary: #112233;', $css);
        $this->assertStringContainsString('--td-colors-accent: #f59e0b;', $css);
        // Values are emitted verbatim — the theme's CSS maps keys like
        // "wide" or "sans" to concrete stacks/widths via body classes, so the
        // core stays free of font/locale-specific vocabulary.
        $this->assertStringContainsString('--td-layout-content-width: wide;', $css);
        $this->assertStringContainsString('--td-layout-sidebar: right;', $css);
        // Booleans have no CSS projection, so `sticky` shouldn't appear.
        $this->assertStringNotContainsString('sticky', $css);
    }

    public function testCssRendererRejectsInjection(): void
    {
        $this->service->save(['colors.primary' => "#fff; } body { display:none ;"]);

        $css = (new ThemeStyleRenderer($this->service))->renderCssVariables();

        $this->assertStringNotContainsString('}', substr($css, strpos($css, 'primary') ?: 0, 80));
        $this->assertStringNotContainsString(';', substr($css, strpos($css, '#fff') ?: 0, 30));
    }
}

/**
 * Minimal ThemeLoader stand-in that returns a canned config and treats the
 * in-memory DB as the source of truth for the active theme name.
 */
final class StubThemeLoader extends ThemeLoader
{
    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
        parent::__construct();
    }

    public function loadThemeConfig(string $themeName = 'default'): array
    {
        return $this->config;
    }

    public function resolveActiveTheme(\PDO $pdo): string
    {
        return 'test';
    }
}
