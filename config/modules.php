<?php
declare(strict_types=1);

return [
    'modules' => [
        'Collection' => (bool) env('MODULE_COLLECTION', false),
        'Redirect'   => (bool) env('MODULE_REDIRECT', false),
        'Mail'       => (bool) env('MODULE_MAIL', false),
        'Antispam'   => (bool) env('MODULE_ANTISPAM', false),
        'Backup'     => (bool) env('MODULE_BACKUP', false),
        'Multilang'  => (bool) env('MODULE_MULTILANG', false),
    ],
    'plugins' => [
        'Form'           => (bool) env('PLUGIN_FORM', false),
        'Social'         => (bool) env('PLUGIN_SOCIAL', false),
        'AdvancedBlocks' => (bool) env('PLUGIN_ADVANCED_BLOCKS', false),
        'ImageOptimizer' => (bool) env('PLUGIN_IMAGE_OPTIMIZER', false),
    ],
];
