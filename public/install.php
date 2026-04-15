<?php
declare(strict_types=1);

/**
 * TypeDock browser installation wizard.
 *
 * Entered automatically when .env or storage/.installed are missing.
 * Deletes or locks itself after successful installation.
 */

$root = dirname(__DIR__);
define('TYPEDOCK_ROOT', $root);

require $root . '/vendor/autoload.php';

use TypeDock\Install\Installer;

// --- Load existing config.php / .env (if partial install) ---
typedock_load_config($root);

$installer = new Installer($root);

// --- Refuse to run after installation ---
if (Installer::isInstalled($root)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Installed</title>';
    echo '<h1>TypeDock is already installed.</h1>';
    echo '<p>Please delete <code>public/install.php</code> for security.</p>';
    exit;
}

// --- Session for CSRF & step state ---
session_name('td_install');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$step   = $_POST['step'] ?? $_GET['step'] ?? 'welcome';
$errors = [];
$data   = $_SESSION['install_data'] ?? [];

if ($method === 'POST') {
    // CSRF check
    if (!hash_equals($csrf, (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        exit('CSRF token mismatch.');
    }
    // Origin / Host check
    $host   = $_SERVER['HTTP_HOST'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin !== '' && $host !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . $host, PHP_URL_HOST)) {
        http_response_code(400);
        exit('Origin mismatch.');
    }

    switch ($step) {
        case 'database':
            $db = [
                'driver'      => $_POST['db_driver'] ?? 'mysql',
                'host'        => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
                'port'        => (int) ($_POST['db_port'] ?? 3306),
                'database'    => trim((string) ($_POST['db_database'] ?? '')),
                'username'    => (string) ($_POST['db_username'] ?? ''),
                'password'    => (string) ($_POST['db_password'] ?? ''),
                'charset'     => 'utf8mb4',
                'sqlite_path' => trim((string) ($_POST['db_sqlite_path'] ?? $root . '/storage/database.sqlite')),
            ];
            $err = $installer->testDatabase($db);
            if ($err !== null) {
                $errors[] = 'Database connection failed: ' . $err;
                $data['db'] = $db;
                $step = 'database';
            } else {
                $data['db'] = $db;
                $_SESSION['install_data'] = $data;
                $step = 'site';
            }
            break;

        case 'site':
            $data['site'] = [
                'name'     => trim((string) ($_POST['site_name'] ?? 'TypeDock')),
                'url'      => trim((string) ($_POST['site_url'] ?? '')),
                'locale'   => trim((string) ($_POST['site_locale'] ?? 'en')),
                'timezone' => trim((string) ($_POST['site_timezone'] ?? 'UTC')),
            ];
            if ($data['site']['url'] === '') {
                $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
                $data['site']['url'] = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
            $_SESSION['install_data'] = $data;
            $step = 'admin';
            break;

        case 'admin':
            $data['admin'] = [
                'email'    => trim((string) ($_POST['admin_email'] ?? '')),
                'name'     => trim((string) ($_POST['admin_name'] ?? '')),
                'password' => (string) ($_POST['admin_password'] ?? ''),
            ];
            if (!filter_var($data['admin']['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'A valid email address is required.';
            }
            if (strlen($data['admin']['password']) < 12) {
                $errors[] = 'Password must be at least 12 characters.';
            }
            if ($errors === []) {
                $_SESSION['install_data'] = $data;
                $step = 'confirm';
            } else {
                $step = 'admin';
            }
            break;

        case 'run':
            try {
                $installer->beginProgress();

                // 1. Write config.php first so Phinx can load config.
                $db   = $data['db']   ?? [];
                $site = $data['site'] ?? [];
                $installer->writeConfig([
                    'APP_NAME'          => $site['name'] ?? 'TypeDock',
                    'APP_URL'           => $site['url']  ?? '',
                    'APP_DEBUG'         => 'false',
                    'APP_LOCALE'        => $site['locale']   ?? 'en',
                    'APP_TIMEZONE'      => $site['timezone'] ?? 'UTC',
                    'DB_DRIVER'         => $db['driver']   ?? 'mysql',
                    'DB_HOST'           => $db['host']     ?? '127.0.0.1',
                    'DB_PORT'           => (string) ($db['port'] ?? 3306),
                    'DB_DATABASE'       => $db['database'] ?? 'typedock',
                    'DB_USERNAME'       => $db['username'] ?? '',
                    'DB_PASSWORD'       => $db['password'] ?? '',
                    'DB_SQLITE_PATH'    => $db['sqlite_path'] ?? '',
                ]);

                // Reload into $_ENV so phinx.php / config/*.php see the new values this request.
                typedock_load_config($root);

                // 2. Run migrations.
                $installer->runMigrations();

                // 3. Create admin.
                $admin = $data['admin'] ?? [];
                $installer->createAdmin($db, $admin['email'] ?? '', $admin['name'] ?? '', $admin['password'] ?? '');

                // 4. Lock.
                $installer->lock(defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.1.0');

                // 5. Optional self-delete.
                if (!empty($_POST['delete_installer'])) {
                    @unlink(__FILE__);
                }

                $_SESSION['install_done'] = [
                    'url'   => ($site['url'] ?? '') . '/admin/login',
                    'email' => $admin['email'] ?? '',
                ];
                unset($_SESSION['install_data']);
                $step = 'done';
            } catch (\Throwable $e) {
                $installer->endProgress();
                $errors[] = 'Installation failed: ' . $e->getMessage();
                $step = 'confirm';
            }
            break;
    }
}

$env = $installer->checkEnvironment();

// --- Rendering helpers ---
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>TypeDock Installer</title>
<style>
body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; color: #222; }
h1 { border-bottom: 2px solid #333; padding-bottom: .5rem; }
.step { color: #888; font-size: .9rem; margin-bottom: 1rem; }
label { display: block; margin: .75rem 0 .25rem; font-weight: 600; }
input[type=text], input[type=email], input[type=password], input[type=number], select {
  width: 100%; padding: .5rem; border: 1px solid #bbb; border-radius: 4px; font-size: 1rem; box-sizing: border-box;
}
button { background: #2b6cb0; color: #fff; border: 0; padding: .6rem 1.2rem; border-radius: 4px; font-size: 1rem; cursor: pointer; }
button:hover { background: #2c5282; }
.ok { color: #2f855a; }
.bad { color: #c53030; }
.warn { color: #b7791f; }
.errors { background: #fed7d7; border: 1px solid #c53030; padding: .75rem 1rem; border-radius: 4px; margin: 1rem 0; }
table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
td, th { padding: .35rem .5rem; border-bottom: 1px solid #eee; text-align: left; }
code { background: #f1f1f1; padding: 1px 4px; border-radius: 3px; }
.hint { font-size: .85rem; color: #666; margin-top: .25rem; }
</style>
</head>
<body>
<h1>TypeDock Installer</h1>
<div class="step">Step: <strong><?= $e($step) ?></strong></div>

<?php if ($errors !== []): ?>
<div class="errors"><ul><?php foreach ($errors as $msg): ?><li><?= $e($msg) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($step === 'welcome'): ?>
  <p>Welcome. This wizard will set up your TypeDock installation.</p>
  <h2>Environment check</h2>
  <table>
    <tr><th>PHP &ge; 8.2</th><td class="<?= $env['php'] ? 'ok' : 'bad' ?>"><?= PHP_VERSION ?></td></tr>
    <?php foreach ($env['extensions'] as $x => $ok): ?>
      <tr><th>ext-<?= $e($x) ?> (required)</th><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'MISSING' ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($env['optional'] as $x => $ok): ?>
      <tr><th>ext-<?= $e($x) ?></th><td class="<?= $ok ? 'ok' : 'warn' ?>"><?= $ok ? 'OK' : 'not loaded' ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($env['writable'] as $path => $ok): ?>
      <tr><th><?= $e($path) ?> writable</th><td class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'NOT WRITABLE' ?></td></tr>
    <?php endforeach; ?>
    <tr><th>mod_rewrite</th><td class="<?= $env['mod_rewrite'] === false ? 'warn' : 'ok' ?>"><?= $env['mod_rewrite'] === null ? 'unknown' : ($env['mod_rewrite'] ? 'loaded' : 'not detected') ?></td></tr>
  </table>

  <?php
    $blocked = !$env['php']
      || in_array(false, $env['extensions'], true)
      || in_array(false, $env['writable'], true);
  ?>
  <?php if ($blocked): ?>
    <p class="bad">Please resolve the items above before continuing.</p>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="step" value="database">
    <button type="submit" <?= $blocked ? 'disabled' : '' ?>>Next: database</button>
  </form>

<?php elseif ($step === 'database'): ?>
  <?php $db = $data['db'] ?? []; ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="step" value="database">

    <label>Driver</label>
    <select name="db_driver">
      <?php foreach (['mysql', 'pgsql', 'sqlite'] as $d): ?>
        <option value="<?= $d ?>" <?= ($db['driver'] ?? 'mysql') === $d ? 'selected' : '' ?>><?= $d ?></option>
      <?php endforeach; ?>
    </select>

    <label>Host</label>
    <input type="text" name="db_host" value="<?= $e($db['host'] ?? '127.0.0.1') ?>">

    <label>Port</label>
    <input type="number" name="db_port" value="<?= $e($db['port'] ?? 3306) ?>">

    <label>Database name</label>
    <input type="text" name="db_database" value="<?= $e($db['database'] ?? 'typedock') ?>">

    <label>Username</label>
    <input type="text" name="db_username" value="<?= $e($db['username'] ?? 'root') ?>">

    <label>Password</label>
    <input type="password" name="db_password" value="<?= $e($db['password'] ?? '') ?>">

    <label>SQLite path (sqlite only)</label>
    <input type="text" name="db_sqlite_path" value="<?= $e($db['sqlite_path'] ?? $root . '/storage/database.sqlite') ?>">

    <p><button type="submit">Test connection and continue</button></p>
  </form>

<?php elseif ($step === 'site'): ?>
  <?php
    $site = $data['site'] ?? [];
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $defaultUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
  ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="step" value="site">

    <label>Site name</label>
    <input type="text" name="site_name" value="<?= $e($site['name'] ?? 'TypeDock') ?>" required>

    <label>Site URL</label>
    <input type="text" name="site_url" value="<?= $e($site['url'] ?? $defaultUrl) ?>" required>

    <label>Locale</label>
    <input type="text" name="site_locale" value="<?= $e($site['locale'] ?? 'en') ?>">

    <label>Timezone</label>
    <input type="text" name="site_timezone" value="<?= $e($site['timezone'] ?? 'UTC') ?>">

    <p><button type="submit">Next: administrator</button></p>
  </form>

<?php elseif ($step === 'admin'): ?>
  <?php $admin = $data['admin'] ?? []; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="step" value="admin">

    <label>Email</label>
    <input type="email" name="admin_email" value="<?= $e($admin['email'] ?? '') ?>" required>

    <label>Display name</label>
    <input type="text" name="admin_name" value="<?= $e($admin['name'] ?? '') ?>">

    <label>Password (minimum 12 characters)</label>
    <input type="password" name="admin_password" minlength="12" required>
    <div class="hint">Use a unique, high-entropy password. A password manager is strongly recommended.</div>

    <p><button type="submit">Review</button></p>
  </form>

<?php elseif ($step === 'confirm'): ?>
  <?php $db = $data['db'] ?? []; $site = $data['site'] ?? []; $admin = $data['admin'] ?? []; ?>
  <h2>Review &amp; install</h2>
  <table>
    <tr><th>Driver</th><td><?= $e($db['driver'] ?? '') ?></td></tr>
    <tr><th>Database</th><td><?= $e(($db['driver'] ?? '') === 'sqlite' ? ($db['sqlite_path'] ?? '') : ($db['database'] ?? '')) ?></td></tr>
    <tr><th>Site</th><td><?= $e($site['name'] ?? '') ?> (<?= $e($site['url'] ?? '') ?>)</td></tr>
    <tr><th>Admin</th><td><?= $e($admin['email'] ?? '') ?></td></tr>
  </table>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="step" value="run">
    <label><input type="checkbox" name="delete_installer" value="1" checked> Delete <code>install.php</code> after completion (recommended)</label>
    <p><button type="submit">Run installation</button></p>
  </form>

<?php elseif ($step === 'done'): ?>
  <?php $done = $_SESSION['install_done'] ?? []; ?>
  <h2 class="ok">Installation complete.</h2>
  <p>Admin login: <a href="<?= $e($done['url'] ?? '/admin/login') ?>"><?= $e($done['url'] ?? '/admin/login') ?></a></p>
  <p>Account: <code><?= $e($done['email'] ?? '') ?></code></p>
  <?php if (is_file(__FILE__)): ?>
    <p class="warn">For security, delete <code>public/install.php</code> from your server.</p>
  <?php else: ?>
    <p class="ok">Installer file has been removed.</p>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>
