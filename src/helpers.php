<?php
declare(strict_types=1);

if (!function_exists('typedock_load_config')) {
    /**
     * Load TypeDock's central config.php and populate $_ENV.
     *
     * Real environment variables take precedence over values in config.php,
     * so the same file works for both shared-hosting (direct values) and
     * container/PaaS deploys (injected env vars). Falls back to .env parsing
     * for backwards compatibility.
     */
    function typedock_load_config(string $root): bool
    {
        $path = $root . '/config.php';
        if (!is_file($path)) {
            $envFile = $root . '/.env';
            if (is_file($envFile)) {
                foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v, " \t\n\r\0\x0B\"'");
                    if (getenv($k) === false) {
                        $_ENV[$k] = $v;
                        putenv("{$k}={$v}");
                    }
                }
                return true;
            }
            return false;
        }

        $values = require $path;
        if (!is_array($values)) {
            return true;
        }
        foreach ($values as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
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
        return true;
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
