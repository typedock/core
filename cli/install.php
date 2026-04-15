<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

use TypeDock\Install\Installer;

typedock_load_config(TYPEDOCK_ROOT);

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
    $installer->lock('0.1.0');

    $appUrl = $_ENV['APP_URL'] ?? 'http://localhost';
    echo "\nAdmin user created.\n";
    echo "Login URL: {$appUrl}/admin/login\n\n";
    echo "Installation complete.\n";
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
