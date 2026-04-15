<?php
declare(strict_types=1);

define('TYPEDOCK_ROOT', dirname(__DIR__));
define('TYPEDOCK_VERSION', '0.1.0');

if (!is_file(TYPEDOCK_ROOT . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "TypeDock: vendor/ is missing. Run `composer install` or deploy the distribution zip with vendor/ included.";
    exit;
}
require TYPEDOCK_ROOT . '/vendor/autoload.php';

// Redirect to browser installer when not yet installed.
$uriPath   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$hasConfig = is_file(TYPEDOCK_ROOT . '/config.php') || is_file(TYPEDOCK_ROOT . '/.env');
if (!$hasConfig || !\TypeDock\Install\Installer::isInstalled(TYPEDOCK_ROOT)) {
    if ($uriPath !== '/install.php') {
        header('Location: install.php', true, 302);
        exit;
    }
}

typedock_load_config(TYPEDOCK_ROOT);

// Enable verbose error output when APP_DEBUG is on, before any app code runs.
$debug = filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOL);
if ($debug) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Serve theme and plugin static assets directly (bypass Flight routing).
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (preg_match('#^/(themes|plugins)/([^/]+)/assets/(.+)$#', $uri, $m)) {
    $safe = str_replace(['..', "\0"], '', $m[3]);
    $path = TYPEDOCK_ROOT . '/' . $m[1] . '/' . $m[2] . '/assets/' . $safe;
    if (is_file($path)) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'css' => 'text/css',
            'js' => 'application/javascript',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'map', 'json' => 'application/json',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        readfile($path);
        return;
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
    http_response_code(500);
    if ($debug) {
        TypeDock\Core\DebugRenderer::render($e);
    } else {
        error_log('[TypeDock] ' . $e::class . ': ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        \TypeDock\Core\ErrorPage::render('500', 'Internal Server Error');
    }
}
