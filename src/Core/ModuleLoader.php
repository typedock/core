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
        $config  = config('modules.modules', []);
        $modules = [
            'Collection' => \TypeDock\Module\Collection\CollectionModule::class,
            'Redirect'   => \TypeDock\Module\Redirect\RedirectModule::class,
            'Mail'       => \TypeDock\Module\Mail\MailModule::class,
            'Antispam'   => \TypeDock\Module\Antispam\AntispamModule::class,
            'Backup'     => \TypeDock\Module\Backup\BackupModule::class,
            'Multilang'  => \TypeDock\Module\Multilang\MultilangModule::class,
        ];

        foreach ($modules as $name => $class) {
            if (empty($config[$name])) {
                continue;
            }
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
