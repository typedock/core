<?php
declare(strict_types=1);

/**
 * Seed demo content (categories, tags, posts, pages, menus) into an
 * already-installed TypeDock site. Idempotent: re-running skips any
 * row whose slug or menu location already exists.
 *
 * Usage:
 *   php cli/seed.php
 */

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

use TypeDock\Install\Installer;

typedock_load_config(TYPEDOCK_ROOT);

echo "TypeDock Demo Seeder\n====================\n\n";

if (!Installer::isInstalled(TYPEDOCK_ROOT)) {
    echo "Not installed. Run `php cli/install.php` first.\n";
    exit(1);
}

$installer = new Installer(TYPEDOCK_ROOT);
$db = require TYPEDOCK_ROOT . '/config/database.php';

try {
    $created = $installer->seedDemoContent($db);
} catch (Throwable $e) {
    echo "Seed failed: " . $e->getMessage() . "\n";
    exit(1);
}

$total = array_sum($created);
foreach ($created as $resource => $count) {
    echo "  {$resource}: {$count}\n";
}

if ($total === 0) {
    echo "\nNothing to do — demo content already present.\n";
} else {
    echo "\nSeeded {$total} new rows.\n";
}
