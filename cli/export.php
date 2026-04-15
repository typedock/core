<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
require TYPEDOCK_ROOT . '/vendor/autoload.php';

$envFile = TYPEDOCK_ROOT . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

$db     = require TYPEDOCK_ROOT . '/config/database.php';
$driver = $db['driver'] ?? 'mysql';

$dsn = match ($driver) {
    'sqlite' => 'sqlite:' . $db['sqlite_path'],
    'pgsql'  => sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'], $db['port'], $db['database']),
    default  => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['database'], $db['charset']),
};

$pdo = new PDO(
    $dsn,
    $driver === 'sqlite' ? null : $db['username'],
    $driver === 'sqlite' ? null : $db['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$schemaDir = TYPEDOCK_ROOT . '/schema';
if (!is_dir($schemaDir)) {
    mkdir($schemaDir, 0755, true);
}

// Export site_options
$stmt = $pdo->query('SELECT * FROM site_options');
$opts = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
file_put_contents($schemaDir . '/site_options.json', json_encode($opts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Export menus (with nested items)
$stmt  = $pdo->query('SELECT * FROM menus');
$menus = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
foreach ($menus as &$menu) {
    $stmt2         = $pdo->prepare('SELECT * FROM menu_items WHERE menu_id = ? ORDER BY sort_order');
    $stmt2->execute([$menu['id']]);
    $menu['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}
unset($menu);
file_put_contents($schemaDir . '/menus.json', json_encode($menus, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Export slot_placements
$stmt  = $pdo->query('SELECT * FROM slot_placements ORDER BY slot_name, sort_order');
$slots = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
file_put_contents($schemaDir . '/slots.json', json_encode($slots, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Export redirects
$stmt      = $pdo->query('SELECT * FROM redirects');
$redirects = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
file_put_contents($schemaDir . '/redirects.json', json_encode($redirects, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Export complete:\n";
echo "  schema/site_options.json (" . count($opts) . " items)\n";
echo "  schema/menus.json (" . count($menus) . " items)\n";
echo "  schema/slots.json (" . count($slots) . " items)\n";
echo "  schema/redirects.json (" . count($redirects) . " items)\n";
