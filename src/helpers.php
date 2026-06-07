<?php
declare(strict_types=1);

if (!function_exists('typedock_load_config')) {
    /**
     * Load TypeDock's central config.php and populate $_ENV.
     *
     * Real environment variables take precedence over values in config.php,
     * so the same file works for both shared-hosting (direct values) and
     * container/PaaS deploys (injected env vars).
     */
    function typedock_load_config(string $root): bool
    {
        $path = $root . '/config.php';
        if (is_file($path)) {
            $values = require $path;
            if (is_array($values)) {
                foreach ($values as $k => $v) {
                    if (!is_string($k)) {
                        continue;
                    }
                    // Real environment variables always win over config.php.
                    $real = getenv($k);
                    if ($real !== false && $real !== '') {
                        $_ENV[$k] = $real;
                        continue;
                    }
                    $str = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
                    if ($str === '') {
                        continue;
                    }
                    $_ENV[$k] = $str;
                    putenv("{$k}={$str}");
                }
            }
            return true;
        }

        // No config.php — configuration may still be provided entirely via
        // environment variables (docker-compose, Kubernetes, systemd, etc.).
        return getenv('DB_DRIVER') !== false;
    }
}

if (!function_exists('env')) {
    /**
     * Get environment variable value with fallback.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value using dot notation (e.g., 'database.host').
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $cache = [];

        [$file, $rest] = array_pad(explode('.', $key, 2), 2, null);

        if (!isset($cache[$file])) {
            $path = TYPEDOCK_ROOT . '/config/' . $file . '.php';
            if (!file_exists($path)) {
                return $default;
            }
            $cache[$file] = require $path;
        }

        if ($rest === null) {
            return $cache[$file] ?? $default;
        }

        $parts = explode('.', $rest);
        $value = $cache[$file];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}

if (!function_exists('__')) {
    function __(string $original, mixed ...$params): string
    {
        try {
            return \Flight::translator()->translate($original, ...$params);
        } catch (\Throwable) {
            if ($params === []) {
                return $original;
            }
            $values = count($params) === 1 && is_array($params[0] ?? null) ? $params[0] : $params;
            $replace = [];
            foreach ($values as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $replace['{' . (string) $key . '}'] = (string) $value;
                }
            }
            return $replace === [] ? $original : strtr($original, $replace);
        }
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return TYPEDOCK_ROOT . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return TYPEDOCK_ROOT . '/storage' . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('public_path')) {
    /**
     * Filesystem path to the web-accessible directory.
     *
     * Defaults to TYPEDOCK_ROOT/public (standard source layout). The shared-hosting
     * distribution ships with `public_html/` and `typedock/` as sibling folders and
     * sets TYPEDOCK_PUBLIC_DIR at the entry point so asset publishing and uploads
     * target the correct directory.
     */
    function public_path(string $path = ''): string
    {
        $base = defined('TYPEDOCK_PUBLIC_DIR') ? TYPEDOCK_PUBLIC_DIR : TYPEDOCK_ROOT . '/public';
        return $base . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : '');
    }
}

if (!function_exists('typedock_is_https')) {
    /**
     * True when the current request is being served over HTTPS, including
     * the case where TLS is terminated at an upstream proxy (Cloudflare,
     * nginx, etc.) and forwarded as plain HTTP via `X-Forwarded-Proto`.
     */
    function typedock_is_https(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }
        if (((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443) {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return false;
    }
}

if (!function_exists('typedock_configure_session_cookie')) {
    /**
     * Apply hardened session cookie defaults at runtime. Called once from
     * public/index.php before any session_start() so the entire app inherits
     * these params — important for shared-hosting deploys where editing
     * php.ini isn't an option.
     *
     *   - HttpOnly: blocks JS access to the session cookie (XSS mitigation).
     *   - SameSite=Lax: blocks cross-site POSTs while allowing top-level GET
     *     navigation (works with admin login redirect chains).
     *   - Secure: only over real HTTPS, including TLS-terminating proxies.
     *
     * Idempotent — safe to call multiple times. No-op if a session has
     * already been started (PHP cannot change the params after that point).
     */
    function typedock_configure_session_cookie(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $lifetime = (int) ($_ENV['SESSION_LIFETIME'] ?? getenv('SESSION_LIFETIME') ?: 0);
        if ($lifetime < 0) {
            $lifetime = 0;
        }

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => typedock_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if (!function_exists('site_option')) {
    /**
     * Read a site_options row, decoded from JSON. Cached for the lifetime of
     * the request so the Router, breadcrumb builder, SEO service, etc. can
     * all call this freely without producing N trips to the DB.
     *
     * On any failure (DB down, table missing during install, option unset)
     * the supplied $default is returned — callers never have to handle an
     * exception here.
     */
    function site_option(string $key, mixed $default = null): mixed
    {
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $stmt = \Flight::db()->query('SELECT key_name, value FROM site_options');
                foreach ($stmt->fetchAll() as $row) {
                    $cache[(string) $row['key_name']] = json_decode((string) $row['value'], true);
                }
            } catch (\Throwable) {
                // Leave $cache empty; callers fall through to $default.
            }
        }
        $val = $cache[$key] ?? null;
        return $val !== null && $val !== '' ? $val : $default;
    }
}

if (!function_exists('typedock_default_locale')) {
    /**
     * Canonical locale for single-locale public content. Falls back safely
     * during install/migration before Flight services or the locales table
     * are available.
     */
    function typedock_default_locale(): string
    {
        try {
            $locale = (string) \Flight::locales()->defaultLocale();
        } catch (\Throwable) {
            $locale = (string) config('app.locale', 'en');
        }

        $locale = strtolower(trim($locale));
        return $locale !== '' ? $locale : 'en';
    }
}

if (!function_exists('typedock_current_locale')) {
    /**
     * Current request locale when locale routing is enabled, otherwise the
     * site default locale. Admin UI language is intentionally separate.
     */
    function typedock_current_locale(): string
    {
        try {
            $locale = (string) \Flight::locales()->current();
        } catch (\Throwable) {
            $locale = typedock_default_locale();
        }

        $locale = strtolower(trim($locale));
        return $locale !== '' ? $locale : typedock_default_locale();
    }
}

if (!function_exists('posts_archive_slug')) {
    /**
     * The URL path segment used for the posts archive + single posts.
     * Defaults to `blog`; operators can override via Settings → General.
     */
    function posts_archive_slug(): string
    {
        $slug = (string) site_option('site.posts_archive_slug', 'blog');
        return trim($slug, '/') !== '' ? trim($slug, '/') : 'blog';
    }
}

if (!function_exists('post_path')) {
    /**
     * Build a root-relative URL for a post slug, honouring the configured
     * posts_archive_slug. Example: post_path('hello') → '/blog/hello'.
     * When $slug is empty, returns the archive path itself.
     */
    function post_path(string $slug = ''): string
    {
        $prefix = '/' . posts_archive_slug();
        return $slug === '' ? $prefix : $prefix . '/' . ltrim($slug, '/');
    }
}
