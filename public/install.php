<?php
declare(strict_types=1);

/**
 * TypeDock browser installation wizard.
 *
 * Entered automatically when config.php or storage/.installed are missing.
 * Deletes or locks itself after successful installation.
 */

$root = dirname(__DIR__);
define('TYPEDOCK_ROOT', $root);

require $root . '/vendor/autoload.php';

use TypeDock\Install\Installer;

// --- Load existing config.php (if partial install) ---
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

$normalizeDbDriver = static function (string $driver): string {
    return in_array($driver, ['mysql', 'pgsql', 'sqlite'], true) ? $driver : 'mysql';
};

$dbDefaultsFor = static function (string $driver) use ($root): array {
    return match ($driver) {
        'pgsql' => [
            'driver'      => 'pgsql',
            'host'        => '127.0.0.1',
            'port'        => 5432,
            'database'    => 'typedock',
            'username'    => 'postgres',
            'password'    => '',
            'charset'     => 'utf8mb4',
            'sqlite_path' => $root . '/storage/database.sqlite',
        ],
        'sqlite' => [
            'driver'      => 'sqlite',
            'host'        => '',
            'port'        => '',
            'database'    => '',
            'username'    => '',
            'password'    => '',
            'charset'     => 'utf8mb4',
            'sqlite_path' => $root . '/storage/database.sqlite',
        ],
        default => [
            'driver'      => 'mysql',
            'host'        => '127.0.0.1',
            'port'        => 3306,
            'database'    => 'typedock',
            'username'    => 'root',
            'password'    => '',
            'charset'     => 'utf8mb4',
            'sqlite_path' => $root . '/storage/database.sqlite',
        ],
    };
};

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
            // The welcome screen's "Next: database" button also posts
            // step=database without any db_* fields. Treat that as "show the
            // form", not "test the connection" — otherwise we run testDatabase
            // against unset defaults (mysql/127.0.0.1) and the user is bounced
            // back with an error before they've had a chance to enter anything.
            if (!isset($_POST['db_driver'])) {
                break;
            }
            $driver = $normalizeDbDriver((string) ($_POST['db_driver'] ?? 'mysql'));
            $defaults = $dbDefaultsFor($driver);
            $port = trim((string) ($_POST['db_port'] ?? ''));
            $db = [
                'driver'      => $driver,
                'host'        => trim((string) ($_POST['db_host'] ?? $defaults['host'])),
                'port'        => $port === '' ? $defaults['port'] : (int) $port,
                'database'    => trim((string) ($_POST['db_database'] ?? $defaults['database'])),
                'username'    => (string) ($_POST['db_username'] ?? $defaults['username']),
                'password'    => (string) ($_POST['db_password'] ?? ''),
                'charset'     => 'utf8mb4',
                'sqlite_path' => trim((string) ($_POST['db_sqlite_path'] ?? $defaults['sqlite_path'])),
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
            $locale = trim((string) ($_POST['site_locale'] ?? 'en'));
            $timezone = trim((string) ($_POST['site_timezone'] ?? 'UTC'));
            if ($locale === '') {
                $locale = 'en';
            }
            if ($timezone === '') {
                $timezone = 'UTC';
            }
            if (strlen($locale) > 10 || !preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale)) {
                $errors[] = 'Choose a valid language code.';
            }
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true) && $timezone !== 'UTC') {
                $errors[] = 'Choose a valid timezone.';
            }
            $data['site'] = [
                'name'     => trim((string) ($_POST['site_name'] ?? 'TypeDock')),
                'url'      => trim((string) ($_POST['site_url'] ?? '')),
                'locale'   => $locale,
                'timezone' => $timezone,
            ];
            if ($data['site']['url'] === '') {
                $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
                $data['site']['url'] = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            }
            if ($errors === []) {
                $_SESSION['install_data'] = $data;
                $step = 'admin';
            } else {
                $step = 'site';
            }
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

                // 3b. Seed site_options so admin Settings → General is populated.
                $installer->seedSiteOptions($db, $site);

                // 4. Activate the default theme (seeds slot_placements). Non-fatal —
                // a missing default theme should not block install; the operator
                // can pick a theme from /admin/themes afterward.
                try {
                    $installer->activateTheme($db, 'default');
                } catch (\Throwable) {
                    // Silently continue.
                }

                // 5. Lock.
                $installer->lock(defined('TYPEDOCK_VERSION') ? TYPEDOCK_VERSION : '0.1.0');

                // 6. Optional self-delete.
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
$dbDriverDefaults = [
    'mysql'  => $dbDefaultsFor('mysql'),
    'pgsql'  => $dbDefaultsFor('pgsql'),
    'sqlite' => $dbDefaultsFor('sqlite'),
];
$languageOptions = [
    'en' => 'English',
    'en-US' => 'English (United States)',
    'en-GB' => 'English (United Kingdom)',
    'ja' => 'Japanese',
    'es' => 'Spanish',
    'fr' => 'French',
    'de' => 'German',
    'it' => 'Italian',
    'pt-BR' => 'Portuguese (Brazil)',
    'pt' => 'Portuguese',
    'zh-Hans' => 'Chinese (Simplified)',
    'zh-Hant' => 'Chinese (Traditional)',
    'ko' => 'Korean',
    'nl' => 'Dutch',
    'sv' => 'Swedish',
];
$commonTimezones = [
    'UTC' => 'UTC',
    'America/New_York' => 'Eastern Time (New York)',
    'America/Chicago' => 'Central Time (Chicago)',
    'America/Denver' => 'Mountain Time (Denver)',
    'America/Los_Angeles' => 'Pacific Time (Los Angeles)',
    'Europe/London' => 'London',
    'Europe/Paris' => 'Central Europe (Paris)',
    'Europe/Berlin' => 'Central Europe (Berlin)',
    'Asia/Tokyo' => 'Japan (Tokyo)',
    'Asia/Seoul' => 'Korea (Seoul)',
    'Asia/Singapore' => 'Singapore',
    'Australia/Sydney' => 'Australia (Sydney)',
];
$allTimezones = array_values(array_diff(\DateTimeZone::listIdentifiers(), array_keys($commonTimezones)));

// --- Rendering helpers ---
$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$json = fn($v) => json_encode($v, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

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
fieldset { border: 1px solid #ddd; border-radius: 6px; padding: .75rem 1rem 1rem; margin: 1rem 0; }
legend { font-weight: 700; padding: 0 .25rem; }
[hidden] { display: none !important; }
button { background: #2b6cb0; color: #fff; border: 0; padding: .6rem 1.2rem; border-radius: 4px; font-size: 1rem; cursor: pointer; }
button:hover { background: #2c5282; }
button:disabled { background: #aaa; cursor: not-allowed; }
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
      <?php
        // config.php is redundant when configuration is already provided via
        // environment variables (docker-compose, Kubernetes, etc.), so
        // downgrade the check from blocker (bad) to advisory (warn).
        $isConfigCheck = ($path === 'root (for config.php)');
        $class = $ok ? 'ok' : ($isConfigCheck && $env['env_configured'] ? 'warn' : 'bad');
        $label = $ok ? 'OK' : ($isConfigCheck && $env['env_configured'] ? 'NOT WRITABLE (optional — configured via environment)' : 'NOT WRITABLE');
      ?>
      <tr><th><?= $e($path) ?> writable</th><td class="<?= $class ?>"><?= $label ?></td></tr>
    <?php endforeach; ?>
    <tr><th>mod_rewrite</th><td class="<?= $env['mod_rewrite'] === false ? 'warn' : 'ok' ?>"><?= $env['mod_rewrite'] === null ? 'unknown' : ($env['mod_rewrite'] ? 'loaded' : 'not detected') ?></td></tr>
  </table>

  <?php
    $writableBlocked = $env['writable'];
    // When config is provided via environment variables, config.php is
    // optional — don't block the installer on it.
    if ($env['env_configured']) {
        unset($writableBlocked['root (for config.php)']);
    }
    $blocked = !$env['php']
      || in_array(false, $env['extensions'], true)
      || in_array(false, $writableBlocked, true);
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
  <?php
    // Prefer values the operator already submitted this session, then real
    // env vars (docker-compose / Kubernetes), then built-in defaults. This
    // keeps the driver pulldown on SQLite when DB_DRIVER=sqlite is in env,
    // instead of snapping back to MySQL after a form reload.
    $storedDb = $data['db'] ?? $installer->dbFromEnv() ?? [];
    $currentDriver = $normalizeDbDriver((string) ($storedDb['driver'] ?? 'mysql'));
    $db = array_replace($dbDefaultsFor($currentDriver), $storedDb, ['driver' => $currentDriver]);
  ?>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="step" value="database">

    <label for="db_driver">Database type</label>
    <select id="db_driver" name="db_driver" data-db-driver>
      <option value="mysql" <?= $currentDriver === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB</option>
      <option value="pgsql" <?= $currentDriver === 'pgsql' ? 'selected' : '' ?>>PostgreSQL</option>
      <option value="sqlite" <?= $currentDriver === 'sqlite' ? 'selected' : '' ?>>SQLite</option>
    </select>
    <div class="hint" data-driver-hint="mysql">Use MySQL/MariaDB for most shared hosting accounts.</div>
    <div class="hint" data-driver-hint="pgsql" hidden>Use PostgreSQL when your host provides a PostgreSQL database.</div>
    <div class="hint" data-driver-hint="sqlite" hidden>Use SQLite for local testing or small single-file installs.</div>

    <fieldset data-db-fields="server">
      <legend>Database server</legend>

      <label for="db_host">Host</label>
      <input id="db_host" type="text" name="db_host" value="<?= $e($db['host']) ?>" data-db-default-field="host">

      <label for="db_port">Port</label>
      <input id="db_port" type="number" name="db_port" value="<?= $e($db['port']) ?>" data-db-default-field="port">

      <label for="db_database">Database name</label>
      <input id="db_database" type="text" name="db_database" value="<?= $e($db['database']) ?>" data-db-default-field="database">

      <label for="db_username">Username</label>
      <input id="db_username" type="text" name="db_username" value="<?= $e($db['username']) ?>" data-db-default-field="username">

      <label for="db_password">Password</label>
      <input id="db_password" type="password" name="db_password" value="<?= $e($db['password']) ?>">
    </fieldset>

    <fieldset data-db-fields="sqlite">
      <legend>SQLite file</legend>

      <label for="db_sqlite_path">Database file path</label>
      <input id="db_sqlite_path" type="text" name="db_sqlite_path" value="<?= $e($db['sqlite_path']) ?>" data-db-default-field="sqlite_path">
      <div class="hint">The default location is inside TypeDock storage and will be created if possible.</div>
    </fieldset>

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

    <label for="site_locale">Language</label>
    <input id="site_locale" type="text" name="site_locale" list="language-options" value="<?= $e($site['locale'] ?? 'en') ?>" autocomplete="off">
    <datalist id="language-options">
      <?php foreach ($languageOptions as $code => $label): ?>
        <option value="<?= $e($code) ?>" label="<?= $e($label) ?>"></option>
      <?php endforeach; ?>
    </datalist>
    <div class="hint">Choose a language, or enter a standard language code such as <code>en</code> or <code>ja</code>.</div>

    <label for="site_timezone">Timezone</label>
    <input id="site_timezone" type="text" name="site_timezone" list="timezone-options" value="<?= $e($site['timezone'] ?? 'UTC') ?>" autocomplete="off">
    <datalist id="timezone-options">
      <?php foreach ($commonTimezones as $zone => $label): ?>
        <option value="<?= $e($zone) ?>" label="<?= $e($label) ?>"></option>
      <?php endforeach; ?>
      <?php foreach ($allTimezones as $zone): ?>
        <option value="<?= $e($zone) ?>"></option>
      <?php endforeach; ?>
    </datalist>
    <div class="hint">Your browser timezone will be selected automatically when possible.</div>

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
<script>
(() => {
  const driver = document.querySelector('[data-db-driver]');
  if (!driver) {
    return;
  }

  const defaults = <?= $json($dbDriverDefaults) ?>;
  const fields = document.querySelectorAll('[data-db-default-field]');

  const isKnownDefault = (field, value) => Object.values(defaults).some((driverDefaults) => {
    return String(driverDefaults[field] ?? '') === String(value);
  });

  const applyDefaults = () => {
    const driverDefaults = defaults[driver.value] || defaults.mysql;
    fields.forEach((input) => {
      const field = input.dataset.dbDefaultField;
      if (input.value === '' || isKnownDefault(field, input.value)) {
        input.value = driverDefaults[field] ?? '';
      }
    });
  };

  const updateVisibility = () => {
    const isSqlite = driver.value === 'sqlite';
    document.querySelectorAll('[data-db-fields="server"]').forEach((group) => {
      group.hidden = isSqlite;
      group.querySelectorAll('input, select, textarea').forEach((input) => {
        input.disabled = isSqlite;
      });
    });
    document.querySelectorAll('[data-db-fields="sqlite"]').forEach((group) => {
      group.hidden = !isSqlite;
      group.querySelectorAll('input, select, textarea').forEach((input) => {
        input.disabled = !isSqlite;
      });
    });
    document.querySelectorAll('[data-driver-hint]').forEach((hint) => {
      hint.hidden = hint.dataset.driverHint !== driver.value;
    });
  };

  driver.addEventListener('change', () => {
    applyDefaults();
    updateVisibility();
  });
  updateVisibility();
})();

(() => {
  const timezone = document.getElementById('site_timezone');
  if (!timezone || (timezone.value !== '' && timezone.value !== 'UTC')) {
    return;
  }

  try {
    const guessed = Intl.DateTimeFormat().resolvedOptions().timeZone;
    if (guessed) {
      timezone.value = guessed;
    }
  } catch (error) {
    // Keep the server default when the browser cannot report a timezone.
  }
})();
</script>
</body>
</html>
