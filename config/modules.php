<?php
declare(strict_types=1);

return [
    // "Modules" are in the process of being retired (doc28). The remaining
    // entries here are kept only for Collection and Backup until they move
    // into their final Core/Plugin homes. Mail / Multilang live in Core
    // unconditionally. Form, Redirect, Social, and ImageOptimizer are
    // first-party drop-in plugins. Antispam is internal to the Form plugin.
    'modules' => [
        'Collection' => (bool) env('MODULE_COLLECTION', false),
        'Backup'     => (bool) env('MODULE_BACKUP', false),
    ],
    'plugins' => [
        'Form'           => (bool) env('PLUGIN_FORM', false),
        'Social'         => (bool) env('PLUGIN_SOCIAL', false),
        'AdvancedBlocks' => (bool) env('PLUGIN_ADVANCED_BLOCKS', false),
        'ImageOptimizer' => (bool) env('PLUGIN_IMAGE_OPTIMIZER', false),
        'Redirect'       => (bool) env('PLUGIN_REDIRECT', true),
    ],

    // Third-party plugin allowlist. Plugin directories under plugins/<slug>
    // are NOT auto-discovered; only slugs listed here have their code loaded.
    // Set via env PLUGINS_THIRDPARTY="slug-one,slug-two".
    'thirdparty_allowlist' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('PLUGINS_THIRDPARTY', '')))
    )),
];
