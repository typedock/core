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
