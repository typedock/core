<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Core\CacheClearer;
use TypeDock\Theme\ThemeLoader;

class ThemeSettingsController extends BaseAdminController
{
    public function index(): void
    {
        $service     = \Flight::theme_settings();
        $loader      = new ThemeLoader();
        $activeTheme = $loader->resolveActiveTheme(\Flight::db());
        $themeConfig = $loader->loadThemeConfig($activeTheme);

        $this->render('pages/theme-settings/index.latte', [
            'schema'             => $service->getSchema(),
            'values'             => $service->all(),
            'active_theme'       => $activeTheme,
            'active_theme_label' => (string) ($themeConfig['name'] ?? $activeTheme),
            'flash_success'      => $this->getFlash('success'),
            'flash_error'        => $this->getFlash('error'),
        ]);
    }

    public function update(): void
    {
        $service = \Flight::theme_settings();
        $schema  = $service->getSchema();

        if ($schema === []) {
            $this->redirect('/admin/theme-settings', 'This theme has no configurable settings.', 'error');
            return;
        }

        // The form posts `settings[group.field]` so everything arrives under a
        // single `settings` array; service->save() accepts dotted keys and
        // coerces per the schema.
        $input = $_POST['settings'] ?? [];
        if (!is_array($input)) {
            $input = [];
        }

        try {
            $service->save($input);
        } catch (\Throwable $e) {
            $this->redirect('/admin/theme-settings', 'Failed to save theme settings: ' . $e->getMessage(), 'error');
            return;
        }

        $this->redirect('/admin/theme-settings', 'Theme settings saved.');
    }

    public function reset(): void
    {
        \Flight::theme_settings()->reset();
        $this->redirect('/admin/theme-settings', 'Theme settings reset to defaults.');
    }

    public function clearCache(): void
    {
        try {
            $result = (new CacheClearer())->clearTemplateCaches();
        } catch (\Throwable $e) {
            $this->redirect('/admin/theme-settings', 'Failed to clear template cache: ' . $e->getMessage(), 'error');
            return;
        }

        $this->redirect('/admin/theme-settings', sprintf(
            'Template cache cleared (%d files deleted).',
            $result
        ));
    }
}
