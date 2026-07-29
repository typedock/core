<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Redirect;

/**
 * Compile redirect patterns with a bounded PCRE work budget.
 *
 * A pattern is evaluated on every public GET until one matches. Merely
 * checking that it compiles does not prevent catastrophic backtracking, so
 * the limits travel with the regex and apply to every preg_match() call.
 */
final class RegexPattern
{
    public const MAX_BYTES = 500;
    public const MAX_RULES = 100;

    private const MATCH_LIMIT = 100000;
    private const DEPTH_LIMIT = 1000;

    public static function compile(string $pattern): ?string
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_BYTES) {
            return null;
        }

        // Pick a delimiter absent from the pattern. Blindly replacing `#`
        // corrupts an already escaped `\#` into `\\#`, which exposes the
        // delimiter again and rejects an otherwise valid expression.
        $delimiter = null;
        foreach (['#', '~', '!', '%', ';', '@', '`'] as $candidate) {
            if (!str_contains($pattern, $candidate)) {
                $delimiter = $candidate;
                break;
            }
        }
        if ($delimiter === null) {
            return null;
        }

        $regex = $delimiter
            . '(*LIMIT_MATCH=' . self::MATCH_LIMIT . ')'
            . '(*LIMIT_DEPTH=' . self::DEPTH_LIMIT . ')'
            . $pattern
            . $delimiter;

        return @preg_match($regex, '') === false ? null : $regex;
    }
}
