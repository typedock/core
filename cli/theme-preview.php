<?php
declare(strict_types=1);

/**
 * Launch a disposable theme preview site.
 *
 * Usage:
 *   php cli/theme-preview.php <slug> [--port 8080] [--screenshot]
 */

define('TYPEDOCK_ROOT', dirname(__DIR__));
define('TYPEDOCK_VERSION', '0.8.0');

require TYPEDOCK_ROOT . '/vendor/autoload.php';

use Ramsey\Uuid\Uuid;
use TypeDock\Core\AssetPublisher;
use TypeDock\Core\Migration\Migrator;
use TypeDock\Install\Installer;
use TypeDock\Theme\ThemeLoader;

$args = $_SERVER['argv'] ?? [];
array_shift($args);

if ($args === [] || in_array($args[0], ['-h', '--help'], true)) {
    theme_preview_usage();
    exit($args === [] ? 2 : 0);
}

$slug = (string) array_shift($args);
$port = 8080;
$screenshot = false;

for ($i = 0; $i < count($args); $i++) {
    $arg = (string) $args[$i];
    if ($arg === '--screenshot') {
        $screenshot = true;
        continue;
    }
    if ($arg === '--port') {
        $next = $args[$i + 1] ?? null;
        if ($next === null || !ctype_digit((string) $next)) {
            fwrite(STDERR, "--port requires a numeric value.\n");
            exit(2);
        }
        $port = (int) $next;
        $i++;
        continue;
    }
    if (str_starts_with($arg, '--port=')) {
        $value = substr($arg, strlen('--port='));
        if (!ctype_digit($value)) {
            fwrite(STDERR, "--port requires a numeric value.\n");
            exit(2);
        }
        $port = (int) $value;
        continue;
    }

    fwrite(STDERR, "Unknown argument: {$arg}\n");
    theme_preview_usage();
    exit(2);
}

if (!preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
    fwrite(STDERR, "Invalid theme slug: {$slug}\n");
    exit(2);
}

$loader = new ThemeLoader();
if (!$loader->themeExists($slug)) {
    fwrite(STDERR, "Theme not found: themes/{$slug}/theme.json\n");
    exit(1);
}

$previewDir = TYPEDOCK_ROOT . '/.preview/' . $slug;
$dbPath = $previewDir . '/preview.sqlite';
$host = '127.0.0.1';
$baseUrl = "http://{$host}:{$port}";

theme_preview_prepare_dir($previewDir);
if (is_file($dbPath)) {
    unlink($dbPath);
}

theme_preview_set_env([
    'APP_ENV'         => 'preview',
    'APP_DEBUG'       => 'true',
    'APP_NAME'        => 'TypeDock Preview',
    'APP_URL'         => $baseUrl,
    'APP_KEY'         => Installer::generateKey(),
    'SESSION_SECRET'  => Installer::generateKey(),
    'DB_DRIVER'       => 'sqlite',
    'DB_SQLITE_PATH'  => $dbPath,
    'APP_LOCALE'      => 'en',
    'APP_TIMEZONE'    => 'UTC',
    'TYPEDOCK_PREVIEW'=> 'true',
]);

$db = require TYPEDOCK_ROOT . '/config/database.php';
$pdo = theme_preview_pdo($db);

echo "TypeDock Theme Preview\n";
echo "Theme: {$slug}\n";
echo "DB:    " . theme_preview_rel($dbPath) . "\n\n";

$migrator = new Migrator($pdo, 'sqlite', TYPEDOCK_ROOT . '/migrations');
$migrationResult = $migrator->migrate();
if ($migrationResult['errors'] !== []) {
    $first = $migrationResult['errors'][0];
    fwrite(STDERR, "Migration failed at {$first['version']} {$first['name']}: {$first['message']}\n");
    exit(1);
}
echo "Migrations: " . count($migrationResult['applied']) . " applied\n";

$installer = new Installer(TYPEDOCK_ROOT);
$adminId = $installer->createAdmin($db, 'preview-admin@example.test', 'Preview Admin', 'preview-password-123');
$installer->seedSiteOptions($db, [
    'name' => 'TypeDock Preview',
    'description' => 'Disposable theme preview content.',
    'home_mode' => 'archive',
    'posts_archive_slug' => 'blog',
    'posts_archive_label' => 'Blog',
]);
$installer->activateTheme($db, $slug);
$created = $installer->seedDemoContent($db, $adminId);
$extras = theme_preview_seed_extras($pdo);

try {
    (new AssetPublisher(TYPEDOCK_ROOT))->publishTheme($slug);
} catch (Throwable $e) {
    echo "Asset publish warning: {$e->getMessage()}\n";
}

echo "Seeded:\n";
foreach (array_merge($created, $extras) as $resource => $count) {
    echo "  {$resource}: {$count}\n";
}

$urls = theme_preview_urls($pdo, $baseUrl);
echo "\nPreview URLs:\n";
foreach ($urls as $label => $url) {
    printf("  %-8s %s\n", $label . ':', $url);
}

$process = theme_preview_start_server($host, $port);
theme_preview_wait($process, $baseUrl);

if ($screenshot) {
    theme_preview_screenshots($urls, $previewDir);
}

echo "\nServer running. Press Ctrl-C to stop.\n";

register_shutdown_function(static function () use ($process): void {
    if (is_resource($process)) {
        $status = proc_get_status($process);
        if (($status['running'] ?? false) === true) {
            proc_terminate($process);
        }
    }
});

while (true) {
    $status = proc_get_status($process);
    if (($status['running'] ?? false) !== true) {
        $code = $status['exitcode'] ?? 1;
        fwrite(STDERR, "Preview server stopped with exit code {$code}.\n");
        exit((int) $code);
    }
    sleep(1);
}

function theme_preview_usage(): void
{
    fwrite(STDERR, "Usage: php cli/theme-preview.php <slug> [--port 8080] [--screenshot]\n");
}

/**
 * @param array<string, string> $values
 */
function theme_preview_set_env(array $values): void
{
    foreach ($values as $key => $value) {
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

function theme_preview_prepare_dir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

/**
 * @param array<string, mixed> $db
 */
function theme_preview_pdo(array $db): PDO
{
    $pdo = new PDO('sqlite:' . (string) $db['sqlite_path'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/**
 * @return array<string, int>
 */
function theme_preview_seed_extras(PDO $pdo): array
{
    $created = [
        'preview_authors' => 0,
        'preview_media' => 0,
        'preview_seo_meta' => 0,
    ];
    $now = gmdate('Y-m-d H:i:s');

    $authors = [
        ['email' => 'editor@example.test', 'name' => 'Mira Stone', 'slug' => 'mira-stone', 'bio' => 'Editor covering design systems and publishing workflows.'],
        ['email' => 'author@example.test', 'name' => 'Theo Lane', 'slug' => 'theo-lane', 'bio' => 'Writer focused on practical CMS operations.'],
    ];
    $authorIds = [];
    foreach ($authors as $author) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$author['email']]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $id = Uuid::uuid7()->toString();
            $pdo->prepare(
                'INSERT INTO users (id, email, password_hash, name, display_name, slug, bio, role, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $author['email'],
                password_hash('preview-password-123', PASSWORD_BCRYPT),
                $author['name'],
                $author['name'],
                $author['slug'],
                $author['bio'],
                'author',
                $now,
                $now,
            ]);
            $created['preview_authors']++;
        }
        $authorIds[] = (string) $id;
    }

    if ($authorIds !== []) {
        $posts = $pdo->query("SELECT id FROM posts WHERE post_type = 'post' ORDER BY published_at DESC")->fetchAll();
        foreach ($posts as $i => $post) {
            $pdo->prepare('UPDATE posts SET author_id = ?, updated_at = ? WHERE id = ?')
                ->execute([$authorIds[$i % count($authorIds)], $now, $post['id']]);
        }
    }

    $mediaIds = [];
    for ($i = 1; $i <= 10; $i++) {
        $path = "https://picsum.photos/seed/typedock-preview-{$i}/1200/800";
        $stmt = $pdo->prepare('SELECT id FROM media WHERE path = ? LIMIT 1');
        $stmt->execute([$path]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $id = Uuid::uuid7()->toString();
            $pdo->prepare(
                'INSERT INTO media (id, path, original_filename, mime_type, file_size, width, height, alt_text, folder, uploaded_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $path,
                "preview-{$i}.jpg",
                'image/jpeg',
                0,
                1200,
                800,
                "Preview image {$i}",
                '/preview',
                null,
                $now,
            ]);
            $created['preview_media']++;
        }
        $mediaIds[] = (string) $id;
    }

    if ($mediaIds !== []) {
        $posts = $pdo->query("SELECT id, title, excerpt FROM posts WHERE post_type = 'post' ORDER BY published_at DESC")->fetchAll();
        foreach ($posts as $i => $post) {
            $created['preview_seo_meta'] += theme_preview_upsert_seo(
                $pdo,
                'post',
                (string) $post['id'],
                (string) $post['title'],
                (string) ($post['excerpt'] ?? ''),
                $mediaIds[$i % count($mediaIds)]
            );
        }
        $created['preview_seo_meta'] += theme_preview_upsert_seo(
            $pdo,
            'global',
            null,
            'TypeDock Preview',
            'Disposable preview content for TypeDock theme development.',
            $mediaIds[0]
        );
    }

    return $created;
}

function theme_preview_upsert_seo(PDO $pdo, string $targetType, ?string $targetId, string $title, string $description, string $mediaId): int
{
    $now = gmdate('Y-m-d H:i:s');
    if ($targetId === null) {
        $stmt = $pdo->prepare('SELECT id FROM seo_meta WHERE target_type = ? AND target_id IS NULL LIMIT 1');
        $stmt->execute([$targetType]);
    } else {
        $stmt = $pdo->prepare('SELECT id FROM seo_meta WHERE target_type = ? AND target_id = ? LIMIT 1');
        $stmt->execute([$targetType, $targetId]);
    }
    $existing = $stmt->fetchColumn();
    if ($existing !== false) {
        $pdo->prepare(
            'UPDATE seo_meta SET seo_title = ?, meta_description = ?, og_title = ?, og_description = ?, og_image_id = ?, twitter_card = ?, updated_at = ? WHERE id = ?'
        )->execute([$title, $description, $title, $description, $mediaId, 'summary_large_image', $now, $existing]);
        return 0;
    }

    $pdo->prepare(
        'INSERT INTO seo_meta (id, target_type, target_id, seo_title, meta_description, og_title, og_description, og_image_id, twitter_card, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        Uuid::uuid7()->toString(),
        $targetType,
        $targetId,
        $title,
        $description,
        $title,
        $description,
        $mediaId,
        'summary_large_image',
        $now,
        $now,
    ]);

    return 1;
}

/**
 * @return array<string, string>
 */
function theme_preview_urls(PDO $pdo, string $baseUrl): array
{
    $postSlug = (string) $pdo->query("SELECT slug FROM posts WHERE post_type = 'post' ORDER BY published_at DESC LIMIT 1")->fetchColumn();
    $pageSlug = (string) $pdo->query("SELECT slug FROM posts WHERE post_type = 'page' ORDER BY slug LIMIT 1")->fetchColumn();
    $categorySlug = (string) $pdo->query("SELECT slug FROM categories ORDER BY sort_order, name LIMIT 1")->fetchColumn();
    $tagSlug = (string) $pdo->query("SELECT slug FROM tags ORDER BY name LIMIT 1")->fetchColumn();
    $authorSlug = (string) $pdo->query("SELECT slug FROM users WHERE slug IS NOT NULL AND slug <> '' ORDER BY created_at DESC LIMIT 1")->fetchColumn();

    return [
        'home' => $baseUrl . '/',
        'single' => $baseUrl . '/blog/' . rawurlencode($postSlug !== '' ? $postSlug : 'welcome-to-typedock'),
        'page' => $baseUrl . '/' . rawurlencode($pageSlug !== '' ? $pageSlug : 'about'),
        'archive' => $baseUrl . '/blog',
        'category' => $baseUrl . '/category/' . rawurlencode($categorySlug !== '' ? $categorySlug : 'news'),
        'tag' => $baseUrl . '/tag/' . rawurlencode($tagSlug !== '' ? $tagSlug : 'featured'),
        'search' => $baseUrl . '/search?q=theme',
        'author' => $baseUrl . '/author/' . rawurlencode($authorSlug !== '' ? $authorSlug : 'preview-admin'),
        '404' => $baseUrl . '/__preview/404',
        '403' => $baseUrl . '/__preview/403',
        '500' => $baseUrl . '/__preview/500',
    ];
}

/**
 * @return resource
 */
function theme_preview_start_server(string $host, int $port)
{
    $cmd = [
        PHP_BINARY,
        '-S',
        "{$host}:{$port}",
        '-t',
        TYPEDOCK_ROOT . '/public',
        TYPEDOCK_ROOT . '/public/index.php',
    ];

    $env = array_merge([
        'PATH' => (string) getenv('PATH'),
    ], $_ENV, [
        'APP_ENV' => 'preview',
        'TYPEDOCK_PREVIEW' => 'true',
    ]);

    $process = proc_open($cmd, [
        0 => ['file', 'php://stdin', 'r'],
        1 => ['file', 'php://stdout', 'w'],
        2 => ['file', 'php://stderr', 'w'],
    ], $pipes, TYPEDOCK_ROOT, $env);

    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start PHP built-in server.\n");
        exit(1);
    }

    return $process;
}

function theme_preview_wait($process, string $baseUrl): void
{
    $deadline = microtime(true) + 8.0;
    while (microtime(true) < $deadline) {
        $status = proc_get_status($process);
        if (($status['running'] ?? false) !== true) {
            fwrite(STDERR, "Preview server exited before it became ready.\n");
            exit((int) ($status['exitcode'] ?? 1));
        }
        $ctx = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
        $body = @file_get_contents($baseUrl . '/', false, $ctx);
        if ($body !== false) {
            return;
        }
        usleep(200000);
    }
    fwrite(STDERR, "Preview server did not become ready at {$baseUrl}.\n");
    exit(1);
}

/**
 * @param array<string, string> $urls
 */
function theme_preview_screenshots(array $urls, string $previewDir): void
{
    $outDir = $previewDir . '/screenshots';
    theme_preview_prepare_dir($outDir);

    $npx = trim((string) shell_exec('command -v npx 2>/dev/null'));
    if ($npx === '') {
        echo "\nScreenshot skipped: npx not found.\n";
        return;
    }

    foreach ($urls as $label => $url) {
        $target = $outDir . '/' . $label . '.png';
        $cmd = escapeshellarg($npx) . ' --no-install playwright screenshot --full-page '
            . escapeshellarg($url) . ' ' . escapeshellarg($target) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code === 0) {
            echo "Screenshot: " . theme_preview_rel($target) . "\n";
        } else {
            echo "Screenshot skipped for {$label}: " . trim(implode("\n", $output)) . "\n";
            return;
        }
    }
}

function theme_preview_rel(string $path): string
{
    $root = rtrim(TYPEDOCK_ROOT, '/') . '/';
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
}
