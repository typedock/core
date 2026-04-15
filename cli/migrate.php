<?php
declare(strict_types=1);
define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';
// Delegates to Phinx
echo "TypeDock Migrate CLI\n";
echo "Usage: vendor/bin/phinx migrate -c phinx.php\n";
