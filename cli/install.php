<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

use TypeDock\Install\Installer;

typedock_load_config(TYPEDOCK_ROOT);

$withDemo = in_array('--with-demo', array_slice($argv ?? [], 1), true);

echo "TypeDock Installer\n==================\n\n";

$installer = new Installer(TYPEDOCK_ROOT);

if (Installer::isInstalled(TYPEDOCK_ROOT)) {
    echo "Already installed. Remove storage/.installed to re-run.\n";
    exit(0);
}

echo "Running database migrations...\n";
try {
    $installer->runMigrations();
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
echo "Migrations complete.\n\n";

echo "Creating admin user.\n";
$email    = (string) readline('Email address: ');
$name     = (string) readline('Display name: ');
$password = (string) readline('Password (min 12 chars): ');

try {
    $db = require TYPEDOCK_ROOT . '/config/database.php';
    $installer->createAdmin($db, $email, $name, $password);

    // Seed site_options from APP_NAME / defaults so the admin Settings
    // → General page has sensible initial values (otherwise the form
    // renders with blank inputs on a fresh install).
    $installer->seedSiteOptions($db, [
        'name' => (string) ($_ENV['APP_NAME'] ?? 'TypeDock'),
    ]);

    // Seed the active theme + default slot placements so a fresh install has
    // something to render. Admins can switch later from /admin/themes.
    $installer->activateTheme($db, 'default');

    if ($withDemo) {
        echo "\nSeeding demo content (categories, tags, posts, pages, menus)...\n";
        $created = $installer->seedDemoContent($db);
        foreach ($created as $resource => $count) {
            echo "  {$resource}: {$count}\n";
        }
    }

    $installer->lock(defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.8.0');

    // Publish theme/plugin static assets into public/.
    echo "\nPublishing assets...\n";
    $published = (new TypeDock\Core\AssetPublisher(TYPEDOCK_ROOT))->publishAll();
    foreach ($published as $src => $dst) {
        echo '  ' . str_replace(TYPEDOCK_ROOT . '/', '', $src) . " -> " . str_replace(TYPEDOCK_ROOT . '/', '', $dst) . "\n";
    }

    $appUrl = $_ENV['APP_URL'] ?? 'http://localhost';
    echo "\nAdmin user created.\n";
    echo "Login URL: {$appUrl}/admin/login\n\n";
    echo "Installation complete.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
