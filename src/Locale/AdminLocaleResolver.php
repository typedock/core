<?php
declare(strict_types=1);

namespace TypeDock\Locale;

final class AdminLocaleResolver
{
    /** @var array<string, string> */
    private ?array $locales = null;
    private string $defaultLocale;
    private string $cookieName;
    private ?string $current = null;

    public function __construct(
        private readonly string $baseDir,
        string $defaultLocale = 'en',
        string $cookieName = 'typedock_admin_locale',
    ) {
        $this->defaultLocale = $this->normalize($defaultLocale) ?: 'en';
        $this->cookieName = $cookieName;
    }

    public function current(): string
    {
        if ($this->current !== null) {
            return $this->current;
        }

        $requested = $this->normalize((string) ($_GET['lang'] ?? $_GET['admin_locale'] ?? ''));
        if ($this->isSupported($requested)) {
            $this->current = $requested;
            $this->persist($requested);
            return $requested;
        }

        $cookie = $this->normalize((string) ($_COOKIE[$this->cookieName] ?? ''));
        if ($this->isSupported($cookie)) {
            $this->current = $cookie;
            return $cookie;
        }

        foreach ($this->parseAcceptLanguage((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $candidate) {
            if ($this->isSupported($candidate)) {
                $this->current = $candidate;
                return $candidate;
            }
        }

        $this->current = $this->isSupported($this->defaultLocale)
            ? $this->defaultLocale
            : (string) array_key_first($this->locales());
        return $this->current;
    }

    /**
     * @return array<string, string>
     */
    public function locales(): array
    {
        if ($this->locales !== null) {
            return $this->locales;
        }

        $locales = ['en' => 'English'];
        foreach ($this->catalogPaths() as $path) {
            $code = $this->normalize((string) pathinfo($path, PATHINFO_FILENAME));
            if ($code === '') {
                continue;
            }
            $locales[$code] = $this->labelFor($code, $path);
        }

        return $this->locales = $locales;
    }

    private function isSupported(string $locale): bool
    {
        return $locale !== '' && array_key_exists($locale, $this->locales());
    }

    private function normalize(string $locale): string
    {
        $locale = strtolower(str_replace('_', '-', trim($locale)));
        return preg_match('/^[a-z]{2}(?:-[a-z0-9]+)?$/', $locale) ? $locale : '';
    }

    /**
     * @return string[]
     */
    private function parseAcceptLanguage(string $header): array
    {
        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $code = $this->normalize((string) explode(';', trim($part))[0]);
            if ($code === '') {
                continue;
            }
            $candidates[] = $code;
            if (str_contains($code, '-')) {
                $candidates[] = explode('-', $code)[0];
            }
        }
        return array_values(array_unique($candidates));
    }

    /**
     * @return string[]
     */
    private function catalogPaths(): array
    {
        $paths = glob(rtrim($this->baseDir, DIRECTORY_SEPARATOR) . '/*.php');
        return is_array($paths) ? $paths : [];
    }

    private function labelFor(string $code, string $path): string
    {
        try {
            $catalog = require $path;
            if (is_array($catalog) && isset($catalog['__locale_name']) && is_string($catalog['__locale_name'])) {
                return $catalog['__locale_name'];
            }
        } catch (\Throwable) {
            // A broken catalog should not make the login screen unreachable.
        }

        return match ($code) {
            'en' => 'English',
            'ja' => '日本語',
            default => strtoupper($code),
        };
    }

    private function persist(string $locale): void
    {
        if (headers_sent()) {
            return;
        }

        setcookie($this->cookieName, $locale, [
            'expires' => time() + 31536000,
            'path' => '/admin',
            'secure' => function_exists('typedock_is_https') && typedock_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
