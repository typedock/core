<?php
declare(strict_types=1);

if (!defined('TYPEDOCK_ROOT')) {
    define('TYPEDOCK_ROOT', dirname(__DIR__));
}
if (!defined('TYPEDOCK_PUBLIC_DIR')) {
    define('TYPEDOCK_PUBLIC_DIR', __DIR__);
}
if (!defined('TYPEDOCK_VERSION')) {
    define('TYPEDOCK_VERSION', '1.0.0-rc5');
}

if (is_file(TYPEDOCK_ROOT . '/storage/.maintenance')) {
    $state = json_decode((string) @file_get_contents(TYPEDOCK_ROOT . '/storage/.maintenance'), true);
    $token = is_array($state) ? (string) ($state['token'] ?? '') : '';
    $bypass = $token !== '' && hash_equals($token, (string) ($_GET['_maintenance_admin'] ?? ''));
    if (!$bypass) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Content-Type: text/html; charset=utf-8');
        // Never let a CDN hold on to the maintenance page after the site is back.
        header('Cache-Control: private, no-store');
        $page = TYPEDOCK_ROOT . '/storage/.maintenance.html';
        if (is_file($page)) {
            readfile($page);
        } else {
            echo '<!doctype html><meta charset="utf-8"><title>Maintenance</title><h1>Maintenance</h1>';
        }
        exit;
    }
}

if (!is_file(TYPEDOCK_ROOT . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TypeDock: vendor/ is missing. Run `composer install` or deploy the distribution zip with vendor/ included.";
    exit;
}
require TYPEDOCK_ROOT . '/vendor/autoload.php';

// Redirect to browser installer when not yet installed.
$uriPath   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$hasConfig = typedock_load_config(TYPEDOCK_ROOT);
$previewMode = filter_var($_ENV['TYPEDOCK_PREVIEW'] ?? getenv('TYPEDOCK_PREVIEW') ?: 'false', FILTER_VALIDATE_BOOL);
if (!$previewMode && (!$hasConfig || !\TypeDock\Install\Installer::isInstalled(TYPEDOCK_ROOT))) {
    if ($uriPath !== '/install.php') {
        header('Location: install.php', true, 302);
        exit;
    }
}

// Enable verbose error output when APP_DEBUG is on, before any app code runs.
$debug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL);
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Apply HttpOnly / SameSite=Lax / Secure cookie defaults BEFORE any
// session_start() so the whole app inherits them. Done in PHP rather than
// php.ini for shared-hosting compatibility.
typedock_configure_session_cookie();

// Serve theme and plugin static assets via PHP fallback (for php -S dev server).
// In production, the web server should serve public/themes/ and public/plugins/ directly.
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (preg_match('#^/(themes|plugins)/([^/]+)/assets/(.+)$#', $uri, $m)) {
    $bucket = $m[1];
    $slug   = $m[2];
    $rest   = $m[3];

    // Bucket (themes/plugins) is fixed by the regex; slug is untrusted. Allow
    // only the characters we actually use in directory names so the attacker
    // can't pivot via unicode homoglyphs or separators that os-level globs
    // would still expand.
    if (preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
        // Allowlist of asset extensions. An .php/.phtml lookup would never
        // match a real theme asset, but we refuse to hand it to readfile
        // anyway so the fallback can never double as a source disclosure.
        $ext = strtolower(pathinfo($rest, PATHINFO_EXTENSION));
        $mimeMap = [
            'css'   => 'text/css',
            'js'    => 'application/javascript',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'svg'   => 'image/svg+xml',
            'webp'  => 'image/webp',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
            'map'   => 'application/json',
            'json'  => 'application/json',
        ];

        if (isset($mimeMap[$ext])) {
            foreach (['public/' . $bucket . '/' . $slug . '/assets', $bucket . '/' . $slug . '/assets'] as $relRoot) {
                $root = realpath(TYPEDOCK_ROOT . '/' . $relRoot);
                if ($root === false) {
                    continue;
                }
                $candidate = realpath($root . '/' . $rest);
                if ($candidate === false || !is_file($candidate)) {
                    continue;
                }
                // Ensure the resolved path is still inside the intended root
                // — belts and braces against symlink escape.
                if (!str_starts_with($candidate . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                header('Content-Type: ' . $mimeMap[$ext]);
                header('Content-Length: ' . filesize($candidate));
                readfile($candidate);
                return;
            }
        }
    }
}

try {
    (new TypeDock\Core\App())->run();
} catch (\TypeDock\Exception\NotFoundException $e) {
    http_response_code(404);
    \TypeDock\Core\ErrorPage::render('404', $e->getMessage());
} catch (\TypeDock\Exception\ForbiddenException $e) {
    http_response_code(403);
    \TypeDock\Core\ErrorPage::render('403', $e->getMessage());
} catch (\Throwable $e) {
    // A CSRF mismatch (419) reaches here rather than App::handleError()
    // because Flight lets it escape start(). Surfacing it as 419 with the
    // theme's 403 page beats "Internal Server Error" for what is usually
    // just a form left open until the session expired.
    if ($e instanceof \TypeDock\Exception\TypeDockException && (int) $e->getCode() === 419) {
        http_response_code(419);
        \TypeDock\Core\ErrorPage::render('403', 'Your session expired. Please reload the page and try again.');
    } elseif ($debug) {
        http_response_code(500);
        TypeDock\Core\DebugRenderer::render($e);
    } else {
        http_response_code(500);
        error_log('[TypeDock] ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        \TypeDock\Core\ErrorPage::render('500', 'Internal Server Error');
    }
}
