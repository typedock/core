<?php
declare(strict_types=1);

namespace TypeDock\Core;

class PluginLoader
{
    public function load(): void
    {
        $this->loadOfficialPlugins();
        $this->loadThirdPartyPlugins();
    }

    private function loadOfficialPlugins(): void
    {
        $config  = config('modules.plugins', []);
        $plugins = [
            'Form'           => \TypeDock\Plugin\Form\FormPlugin::class,
            'Social'         => \TypeDock\Plugin\Social\SocialPlugin::class,
            'AdvancedBlocks' => \TypeDock\Plugin\AdvancedBlocks\AdvancedBlocksPlugin::class,
            'ImageOptimizer' => \TypeDock\Plugin\ImageOptimizer\ImageOptimizerPlugin::class,
        ];

        foreach ($plugins as $name => $class) {
            if (empty($config[$name])) {
                continue;
            }
            if (!class_exists($class)) {
                continue;
            }
            /** @var \TypeDock\Contract\PluginInterface $plugin */
            $plugin  = new $class();
            $context = new PluginContext(
                strtolower($name),
                \Flight::db()
            );
            $plugin->register($context);
        }
    }

    private function loadThirdPartyPlugins(): void
    {
        $pluginsDir = TYPEDOCK_ROOT . '/plugins';
        if (!is_dir($pluginsDir)) {
            return;
        }

        $dirs = array_filter(glob($pluginsDir . '/*/plugin.json') ?: [], 'is_file');
        foreach ($dirs as $manifestPath) {
            $this->loadThirdPartyPlugin($manifestPath);
        }
    }

    private function loadThirdPartyPlugin(string $manifestPath): void
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['slug']) || empty($manifest['main_class'])) {
            return;
        }

        $pluginDir = dirname($manifestPath);
        $autoload  = $pluginDir . '/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        $class = $manifest['main_class'];
        if (!class_exists($class)) {
            return;
        }

        /** @var \TypeDock\Contract\PluginInterface $plugin */
        $plugin  = new $class();
        $context = new PluginContext(
            (string) $manifest['slug'],
            \Flight::db()
        );
        $plugin->register($context);
    }
}
