<?php
declare(strict_types=1);

namespace TypeDock\Core;

use TypeDock\Contract\ModuleInterface;

class ModuleLoader
{
    /** @var array<string, ModuleInterface> */
    private array $loaded = [];

    public function load(): void
    {
        // Mail, Multilang → Core; Redirect, Backup → Plugin; Antispam → Form
        // Plugin. Collection is the last remaining module until its redesign
        // around external data/feed imports lands.
        $modules = [
            'Collection' => [
                'class' => \TypeDock\Module\Collection\CollectionModule::class,
                'env'   => 'MODULE_COLLECTION',
            ],
        ];

        foreach ($modules as $name => $moduleConfig) {
            if (!(bool) env($moduleConfig['env'], false)) {
                continue;
            }
            $class = $moduleConfig['class'];
            if (!class_exists($class)) {
                continue;
            }
            /** @var ModuleInterface $module */
            $module = new $class();
            $module->register();
            $this->loaded[$name] = $module;
        }
    }

    /** @return array<string, ModuleInterface> */
    public function getLoaded(): array
    {
        return $this->loaded;
    }

    public function isLoaded(string $name): bool
    {
        return isset($this->loaded[$name]);
    }
}
