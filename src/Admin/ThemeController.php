<?php
declare(strict_types=1);

namespace TypeDock\Admin;

use TypeDock\Core\AssetPublisher;
use TypeDock\Core\CacheClearer;
use TypeDock\Theme\ThemeLoader;

class ThemeController extends BaseAdminController
{
    public function index(): void
    {
        $loader  = new ThemeLoader();
        $active  = $loader->resolveActiveTheme(\Flight::db());
        $names   = $loader->listThemes();
        $themes  = [];
        foreach ($names as $name) {
            $config = $loader->loadThemeConfig($name);
            $themes[] = [
                'slug'        => $name,
                'name'        => (string) ($config['name'] ?? $name),
                'version'     => (string) ($config['version'] ?? ''),
                'author'      => (string) ($config['author'] ?? ''),
                'description' => (string) ($config['description'] ?? ''),
                'screenshot'  => $loader->screenshotUrl($name),
                'slot_count'  => is_array($config['slots'] ?? null) ? count($config['slots']) : 0,
                'is_active'   => $name === $active,
            ];
        }

        $this->render('pages/themes/index.latte', [
            'themes'        => $themes,
            'active_theme'  => $active,
            'flash_success' => $this->getFlash('success'),
            'flash_error'   => $this->getFlash('error'),
        ]);
    }

    public function activate(): void
    {
        $slug   = trim((string) ($_POST['theme'] ?? ''));
        $loader = new ThemeLoader();

        if (!$loader->themeExists($slug)) {
            $this->redirect('/admin/themes', 'Unknown theme.', 'error');
            return;
        }

        try {
            $loader->activateTheme($slug, \Flight::db());
        } catch (\Throwable $e) {
            $this->redirect('/admin/themes', 'Failed to activate theme: ' . $e->getMessage(), 'error');
            return;
        }

        $config = $loader->loadThemeConfig($slug);
        $label  = (string) ($config['name'] ?? $slug);
        $this->redirect('/admin/themes', "Activated theme: {$label}");
    }

    /**
     * Open the site homepage in preview mode for the requested theme. We publish
     * the theme's static assets first so the preview tab doesn't 404 on CSS
     * before the operator has activated anything.
     */
    public function preview(): void
    {
        $slug   = trim((string) ($_GET['theme'] ?? ''));
        $loader = new ThemeLoader();

        if (!$loader->themeExists($slug)) {
            $this->redirect('/admin/themes', 'Unknown theme.', 'error');
            return;
        }

        try {
            (new AssetPublisher(TYPEDOCK_ROOT))->publishTheme($slug);
            (new CacheClearer())->clearTemplateCaches();
        } catch (\Throwable) {
            // Non-fatal — PHP readfile() fallback will still serve the assets.
        }

        \Flight::redirect('/?preview_theme=' . rawurlencode($slug));
        exit;
    }
}
