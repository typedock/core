<?php
declare(strict_types=1);

namespace TypeDock\Locale;

final class Translator
{
    /** @var array<string, string>|null */
    private ?array $catalog = null;

    public function __construct(
        private readonly string $locale,
        private readonly string $baseDir,
    ) {}

    public function translate(string $original, mixed ...$params): string
    {
        $translated = $this->catalog()[$original] ?? $original;
        $values = $this->normalizeParams($params);
        if ($values === []) {
            return $translated;
        }

        $replace = [];
        foreach ($values as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $replace['{' . (string) $key . '}'] = (string) $value;
            }
        }
        return $replace === [] ? $translated : strtr($translated, $replace);
    }

    /**
     * @return array<string, string>
     */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $path = $this->baseDir . '/' . $this->locale . '.php';
        if (!is_file($path)) {
            return $this->catalog = [];
        }

        $catalog = require $path;
        if (!is_array($catalog)) {
            return $this->catalog = [];
        }

        $out = [];
        foreach ($catalog as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }
        return $this->catalog = $out;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int|string, mixed>
     */
    private function normalizeParams(array $params): array
    {
        if (count($params) === 1 && is_array($params[0] ?? null)) {
            return $params[0];
        }
        return $params;
    }
}
