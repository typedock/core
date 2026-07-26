<?php
declare(strict_types=1);

namespace TypeDock\Plugin\ImportWordPress;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class WordPressImporterPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        // WXR is XML, and streaming it is the only way a 200MB export fits in
        // a shared host's memory limit. Without ext-xmlreader the importer
        // cannot work at all — say so in the diagnostics panel rather than
        // registering an importer that explodes when someone picks it.
        // (`xmlreader` is not in composer.json's requires nor in the
        // installer's extension check, so this is the only place it surfaces.)
        if (!extension_loaded('xmlreader')) {
            \Flight::plugin_diagnostics()->error(
                'import-wordpress',
                'PHP extension "xmlreader" is not installed, so WordPress export files cannot be read.'
            );
            return;
        }

        $context->registerImporter(new WxrImporter());
    }

    public function getName(): string
    {
        return 'WordPress Importer';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    /** @return array<int, string> */
    public function provides(): array
    {
        return [];
    }
}
