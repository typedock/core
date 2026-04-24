<?php
declare(strict_types=1);

namespace TypeDock\Locale;

/**
 * LocaleService — manages active locales and the current request locale.
 */
class LocaleService
{
    private ?string $current = null;

    public function __construct(private readonly \PDO $pdo) {}

    /** @return array<array<string, mixed>> */
    public function listActive(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT * FROM locales WHERE is_active = 1 ORDER BY sort_order, code'
            );
            $rows = $stmt ? $stmt->fetchAll() : [];
        } catch (\Throwable) {
            $rows = [];
        }
        if ($rows === []) {
            // fall back to a single-locale config so the site never breaks
            $rows = [[
                'code' => $this->defaultLocale(),
                'name' => 'Default',
                'is_default' => 1,
                'is_active' => 1,
                'sort_order' => 0,
            ]];
        }
        return $rows;
    }

    public function defaultLocale(): string
    {
        try {
            $stmt = $this->pdo->query('SELECT code FROM locales WHERE is_default = 1 LIMIT 1');
            $row  = $stmt ? $stmt->fetch() : false;
            if ($row !== false) {
                return (string) $row['code'];
            }
        } catch (\Throwable) {
            // table may not exist yet
        }
        return (string) config('app.locale', 'en');
    }

    public function isActive(string $code): bool
    {
        foreach ($this->listActive() as $row) {
            if ($row['code'] === $code) {
                return true;
            }
        }
        return false;
    }

    public function setCurrent(string $code): void
    {
        $this->current = $code;
    }

    public function current(): string
    {
        return $this->current ?? $this->defaultLocale();
    }

    public function add(string $code, string $name, bool $isDefault = false): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // emulate upsert without ON DUPLICATE KEY UPDATE
        $existing = $this->pdo->prepare('SELECT code FROM locales WHERE code = ?');
        $existing->execute([$code]);
        if ($existing->fetch() === false) {
            $this->pdo->prepare(
                'INSERT INTO locales (code, name, is_default, is_active, sort_order, created_at) VALUES (?, ?, ?, 1, 0, ?)'
            )->execute([$code, $name, $isDefault ? 1 : 0, $now]);
        } else {
            $this->pdo->prepare('UPDATE locales SET name = ?, is_default = ? WHERE code = ?')
                ->execute([$name, $isDefault ? 1 : 0, $code]);
        }

        if ($isDefault) {
            $this->pdo->prepare('UPDATE locales SET is_default = 0 WHERE code <> ?')->execute([$code]);
        }
    }

    /**
     * Resolve the locale for a request from a path prefix or Accept-Language.
     * Returns [resolved_code, stripped_path].
     *
     * @return array{0: string, 1: string}
     */
    public function resolveFromRequest(string $path, string $acceptLanguage = ''): array
    {
        $active = array_column($this->listActive(), 'code');

        // 1. URL prefix (e.g. /ja/about)
        if (preg_match('#^/([a-z]{2}(?:-[a-zA-Z0-9]+)?)(/.*)?$#', $path, $m)) {
            $candidate = strtolower($m[1]);
            if (in_array($candidate, $active, true)) {
                return [$candidate, $m[2] ?? '/'];
            }
        }

        // 2. Accept-Language header
        if ($acceptLanguage !== '') {
            foreach ($this->parseAcceptLanguage($acceptLanguage) as $code) {
                if (in_array($code, $active, true)) {
                    return [$code, $path];
                }
            }
        }

        return [$this->defaultLocale(), $path];
    }

    /** @return string[] */
    private function parseAcceptLanguage(string $header): array
    {
        $codes = [];
        foreach (explode(',', $header) as $part) {
            $part = trim(explode(';', $part)[0]);
            if ($part === '') {
                continue;
            }
            $codes[] = strtolower($part);
            // also try the primary subtag
            if (str_contains($part, '-')) {
                $codes[] = strtolower(explode('-', $part)[0]);
            }
        }
        return array_values(array_unique($codes));
    }
}
