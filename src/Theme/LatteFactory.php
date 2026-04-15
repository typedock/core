<?php
declare(strict_types=1);

namespace TypeDock\Theme;

use Latte\Engine;

class LatteFactory
{
    private ?Engine $engine = null;
    private string $activeTheme = 'default';

    public function getEngine(): Engine
    {
        if ($this->engine !== null) {
            return $this->engine;
        }

        $latteDir = config('cache.latte_dir', TYPEDOCK_ROOT . '/storage/cache/latte');
        if (!is_dir($latteDir)) {
            mkdir($latteDir, 0755, true);
        }

        $engine = new Engine();
        $engine->setTempDirectory($latteDir);
        $engine->setAutoRefresh((bool) config('app.debug', false));

        // Register CMS extension (custom tags)
        $engine->addExtension(new CmsLatteExtension());

        $this->engine = $engine;
        return $engine;
    }

    /**
     * Set the template path and render to output.
     *
     * @param  array<string, mixed> $params
     */
    public function render(string $template, array $params = [], ?string $baseDir = null): void
    {
        $path = $this->resolvePath($template, $baseDir);
        $this->getEngine()->render($path, $params);
    }

    /**
     * Render to string.
     *
     * @param  array<string, mixed> $params
     */
    public function renderToString(string $template, array $params = [], ?string $baseDir = null): string
    {
        $path = $this->resolvePath($template, $baseDir);
        return $this->getEngine()->renderToString($path, $params);
    }

    /**
     * Resolve template path: theme dir first, then fallback.
     */
    public function resolvePath(string $template, ?string $baseDir = null): string
    {
        if ($baseDir !== null) {
            $absolute = $baseDir . '/' . ltrim($template, '/');
            if (file_exists($absolute)) {
                return $absolute;
            }
        }

        $themeDir = TYPEDOCK_ROOT . '/themes/' . $this->activeTheme;
        $inTheme  = $themeDir . '/' . ltrim($template, '/');
        if (file_exists($inTheme)) {
            return $inTheme;
        }

        // Admin templates
        $adminDir = TYPEDOCK_ROOT . '/admin';
        $inAdmin  = $adminDir . '/' . ltrim($template, '/');
        if (file_exists($inAdmin)) {
            return $inAdmin;
        }

        return $inTheme; // Return theme path even if not found (will error with nice message)
    }

    public function setActiveTheme(string $theme): void
    {
        $this->activeTheme = $theme;
    }

    public function getActiveTheme(): string
    {
        return $this->activeTheme;
    }
}
