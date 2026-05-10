<?php
declare(strict_types=1);

namespace TypeDock\Install;

use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use TypeDock\Core\Database\SqlitePragmas;
use TypeDock\Core\Migration\Migrator;

/**
 * Shared installation logic used by both the CLI and the browser wizard.
 *
 * Safe to call individual steps independently; callers decide ordering.
 */
final class Installer
{
    public const LOCK_FILE      = '/storage/.installed';
    public const PROGRESS_FILE  = '/storage/.installing';

    public function __construct(private string $root)
    {
    }

    public static function isInstalled(string $root): bool
    {
        return is_file($root . self::LOCK_FILE);
    }

    /**
     * @return array{php:bool,extensions:array<string,bool>,optional:array<string,bool>,writable:array<string,bool>,env_configured:bool,mod_rewrite:bool|null}
     */
    public function checkEnvironment(): array
    {
        $required = ['pdo', 'mbstring', 'json', 'openssl', 'fileinfo'];
        $optional = ['gd', 'intl', 'pdo_mysql', 'pdo_pgsql', 'pdo_sqlite'];

        $ext = [];
        foreach ($required as $e) {
            $ext[$e] = extension_loaded($e);
        }
        $opt = [];
        foreach ($optional as $e) {
            $opt[$e] = extension_loaded($e);
        }

        $publicDir = defined('TYPEDOCK_PUBLIC_DIR') ? TYPEDOCK_PUBLIC_DIR : $this->root . '/public';
        $writable = [
            'storage'          => is_writable($this->root . '/storage'),
            'storage/cache'    => is_writable($this->root . '/storage/cache'),
            'storage/logs'     => is_writable($this->root . '/storage/logs'),
            'storage/sessions' => is_writable($this->root . '/storage/sessions'),
            'uploads'          => is_writable($publicDir . '/uploads'),
            'root (for config.php)' => is_writable($this->root),
        ];

        $rewrite = null;
        if (function_exists('apache_get_modules')) {
            $rewrite = in_array('mod_rewrite', apache_get_modules(), true);
        }

        // When DB_DRIVER is already provided as a real environment variable
        // (e.g. docker-compose, Kubernetes, systemd), config.php is redundant.
        $envConfigured = (getenv('DB_DRIVER') !== false);

        return [
            'php'            => PHP_VERSION_ID >= 80200,
            'extensions'     => $ext,
            'optional'       => $opt,
            'writable'       => $writable,
            'env_configured' => $envConfigured,
            'mod_rewrite'    => $rewrite,
        ];
    }

    /**
     * Test a DB connection with the given config. Returns null on success, message on failure.
     *
     * @param array{driver:string,host?:string,port?:int|string,database?:string,username?:string,password?:string,sqlite_path?:string,charset?:string} $db
     */
    public function testDatabase(array $db): ?string
    {
        try {
            // For SQLite, the file is auto-created by PDO on connect, but only
            // if its parent directory already exists. Create it proactively so
            // operators pointing at a fresh storage path don't get a misleading
            // connection error before the wizard can surface it.
            if (($db['driver'] ?? '') === 'sqlite') {
                $path = (string) ($db['sqlite_path'] ?? '');
                if ($path !== '') {
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                }
            }
            $this->makePdo($db);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Build a DB config array from real environment variables. Returns null
     * when DB_DRIVER is not set, signalling the wizard should use its own
     * defaults.
     *
     * @return array{driver:string,host:string,port:int,database:string,username:string,password:string,charset:string,sqlite_path:string}|null
     */
    public function dbFromEnv(): ?array
    {
        $driver = getenv('DB_DRIVER');
        if ($driver === false || $driver === '') {
            return null;
        }
        $sqlitePath = getenv('DB_SQLITE_PATH');
        return [
            'driver'      => (string) $driver,
            'host'        => (string) (getenv('DB_HOST') ?: '127.0.0.1'),
            'port'        => (int) (getenv('DB_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306)),
            'database'    => (string) (getenv('DB_DATABASE') ?: ''),
            'username'    => (string) (getenv('DB_USERNAME') ?: ''),
            'password'    => (string) (getenv('DB_PASSWORD') ?: ''),
            'charset'     => (string) (getenv('DB_CHARSET') ?: 'utf8mb4'),
            'sqlite_path' => (string) ($sqlitePath !== false && $sqlitePath !== ''
                ? $sqlitePath
                : $this->root . '/storage/database.sqlite'),
        ];
    }

    /**
     * Write config.php from the given values, merged on top of config.php.example defaults.
     *
     * The output is valid PHP ("return [...];"), produced via var_export for each value
     * — never string interpolation — so user-supplied values cannot inject code.
     *
     * @param array<string,string> $values
     */
    public function writeConfig(array $values): void
    {
        $values['APP_KEY']        = ($values['APP_KEY']        ?? '') ?: self::generateKey();
        $values['SESSION_SECRET'] = ($values['SESSION_SECRET'] ?? '') ?: self::generateKey();

        $defaults = [];
        $template = $this->root . '/config.php.example';
        if (is_file($template)) {
            $loaded = require $template;
            if (is_array($loaded)) {
                $defaults = $loaded;
            }
        }

        $final = $defaults;
        foreach ($values as $k => $v) {
            $final[$k] = (string) $v;
        }

        $lines = [
            '<?php',
            '/**',
            ' * TypeDock configuration.',
            ' * Generated by the installer on ' . gmdate('c') . '.',
            ' *',
            ' * Real environment variables override any value here. Edit this file to change',
            ' * runtime configuration on shared hosting; rotate APP_KEY and SESSION_SECRET if',
            ' * ever exposed.',
            ' */',
            '',
            'declare(strict_types=1);',
            '',
            'return [',
        ];
        foreach ($final as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $lines[] = sprintf('    %s => %s,', var_export($k, true), var_export((string) $v, true));
        }
        $lines[] = '];';
        $lines[] = '';

        $path = $this->root . '/config.php';
        $written = @file_put_contents($path, implode("\n", $lines), LOCK_EX);
        if ($written === false) {
            // When configuration is already provided via environment variables
            // (docker-compose, Kubernetes, systemd, etc.), config.php is
            // redundant — skip without error.
            if (getenv('DB_DRIVER') === false) {
                throw new RuntimeException('Failed to write ' . $path);
            }
            return;
        }
        @chmod($path, 0600);
    }

    /**
     * Run all pending migrations via the PHP API (no shell). Safe to call from
     * both the CLI and the browser install wizard.
     *
     * @return array{applied: list<array{version:string,name:string}>, errors: list<array{version:string,name:string,message:string}>}
     */
    public function runMigrations(): array
    {
        $db  = require $this->root . '/config/database.php';
        $pdo = $this->makePdo($db);
        $migrator = new Migrator($pdo, (string) $db['driver'], $this->root . '/migrations');
        return $migrator->migrate();
    }

    /**
     * Insert the initial admin user.
     *
     * @param array{driver:string,host?:string,port?:int|string,database?:string,username?:string,password?:string,sqlite_path?:string,charset?:string} $db
     */
    public function createAdmin(array $db, string $email, string $name, string $password): string
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('A valid email address is required.');
        }
        if (strlen($password) < 12) {
            throw new RuntimeException('Password must be at least 12 characters.');
        }

        $pdo  = $this->makePdo($db);
        $id   = Uuid::uuid7()->toString();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $now  = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $displayName = $name !== '' ? $name : $email;
        // Author archive routes (`/author/<slug>`) need a slug to resolve.
        // Derive one up-front so the very first install has a working
        // author page without requiring the operator to edit their profile.
        $slug = \TypeDock\Content\TermSlugger::normalize($displayName, 'admin');

        $pdo->prepare(
            'INSERT INTO users (id, email, password_hash, name, slug, role, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $email, $hash, $displayName, $slug, 'admin', $now, $now]);

        return $id;
    }

    /**
     * Activate a theme during install. Seeds `site_options.theme.active` and the
     * `slot_placements` defaults declared in that theme's theme.json so a fresh
     * site has something to render before the operator touches the admin.
     *
     * @param array{driver:string,host?:string,port?:int|string,database?:string,username?:string,password?:string,sqlite_path?:string,charset?:string} $db
     */
    public function activateTheme(array $db, string $themeName = 'default'): void
    {
        $pdo = $this->makePdo($db);
        (new \TypeDock\Theme\ThemeLoader())->activateTheme($themeName, $pdo);
    }

    /**
     * Seed the `site_options` table with the values collected by the wizard.
     * Without this the admin Settings → General page renders with empty
     * fields on a fresh install, because `SettingsController::getOptions()`
     * only reads from this table (not from config.php).
     *
     * Safe to re-run: each key is upserted in place.
     *
     * @param array{driver:string,host?:string,port?:int|string,database?:string,username?:string,password?:string,sqlite_path?:string,charset?:string} $db
     * @param array{name?:string,description?:string,home_mode?:string,home_page_id?:string|null,posts_archive_slug?:string,posts_archive_label?:string} $site
     */
    public function seedSiteOptions(array $db, array $site): void
    {
        $pdo = $this->makePdo($db);

        $defaults = [
            'site.name'                 => $site['name']                 ?? 'TypeDock',
            'site.description'          => $site['description']          ?? '',
            'site.home_mode'            => $site['home_mode']            ?? 'archive',
            'site.home_page_id'         => $site['home_page_id']         ?? null,
            'site.posts_archive_slug'   => $site['posts_archive_slug']   ?? 'blog',
            'site.posts_archive_label' => $site['posts_archive_label'] ?? 'Blog',
        ];

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $select = $pdo->prepare('SELECT key_name FROM site_options WHERE key_name = ? LIMIT 1');
        $update = $pdo->prepare('UPDATE site_options SET value = ?, group_name = ?, updated_at = ? WHERE key_name = ?');
        $insert = $pdo->prepare('INSERT INTO site_options (key_name, value, group_name, updated_at) VALUES (?, ?, ?, ?)');

        foreach ($defaults as $key => $value) {
            $json = json_encode($value);
            $select->execute([$key]);
            if ($select->fetch() !== false) {
                $update->execute([$json, 'general', $now, $key]);
            } else {
                $insert->execute([$key, $json, 'general', $now]);
            }
        }
    }

    /**
     * Run the demo content seeder against the connected database. Every
     * insert is checked for slug/location collisions first so the call is
     * a safe no-op when the demo content already exists — operators can
     * re-run `cli/seed.php` after a fresh `cli/install.php --with-demo`
     * without truncating their work.
     *
     * @param array{driver:string,host?:string,port?:int|string,database?:string,username?:string,password?:string,sqlite_path?:string,charset?:string} $db
     * @return array<string, int> per-resource count of rows actually created
     */
    public function seedDemoContent(array $db, ?string $authorId = null): array
    {
        $pdo = $this->makePdo($db);
        return (new DemoSeeder($pdo))->seed($authorId);
    }

    public function lock(string $version): void
    {
        $payload = json_encode([
            'version'      => $version,
            'installed_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents($this->root . self::LOCK_FILE, (string) $payload, LOCK_EX);
        @unlink($this->root . self::PROGRESS_FILE);
    }

    public function beginProgress(): void
    {
        $path = $this->root . self::PROGRESS_FILE;
        if (is_file($path) && (time() - filemtime($path)) < 600) {
            throw new RuntimeException('Installation is already running.');
        }
        file_put_contents($path, (string) time(), LOCK_EX);
    }

    public function endProgress(): void
    {
        @unlink($this->root . self::PROGRESS_FILE);
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * @param array<string,mixed> $db
     */
    private function makePdo(array $db): PDO
    {
        $driver  = $db['driver'] ?? 'mysql';
        $charset = $db['charset'] ?? 'utf8mb4';

        $dsn = match ($driver) {
            'sqlite' => 'sqlite:' . ($db['sqlite_path'] ?? $this->root . '/storage/database.sqlite'),
            'pgsql'  => sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'] ?? '127.0.0.1', (int) ($db['port'] ?? 5432), $db['database'] ?? ''),
            default  => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'] ?? '127.0.0.1', (int) ($db['port'] ?? 3306), $db['database'] ?? '', $charset),
        };

        $pdo = new PDO(
            $dsn,
            $driver === 'sqlite' ? null : ($db['username'] ?? ''),
            $driver === 'sqlite' ? null : ($db['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        if ($driver === 'sqlite') {
            SqlitePragmas::apply($pdo, $db);
        }

        return $pdo;
    }

}
