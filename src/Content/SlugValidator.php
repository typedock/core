<?php
declare(strict_types=1);

namespace TypeDock\Content;

class SlugValidator
{
    private const RESERVED_SLUGS = [
        'admin', 'api', 'feed', 'sitemap.xml', 'robots.txt',
        'blog', 'category', 'tag', 'search', 'plugin',
        'storage', 'assets', 'public',
    ];

    /**
     * The one definition of what a slug is made of: base letters and digits
     * from any script. Referenced by TermSlugger and the importer so the four
     * places that build a slug cannot drift apart.
     *
     * Combining marks (\p{M}) are deliberately absent. Nothing normalises
     * Unicode here — see isResolvable() — so allowing them would let `が`
     * exist twice, precomposed (U+304C) and decomposed (U+304B U+3099), as two
     * rows that look identical and answer to different URLs. Refusing the
     * decomposed form at the boundary is what makes a byte comparison a
     * correct comparison. The cost is that scripts whose marks are structural
     * rather than optional — Devanagari, Thai — cannot be used in a slug yet.
     *
     * \p{Lm} stays in: U+30FC (コーヒー) and U+3005 (人々) are modifier
     * letters and are ordinary Japanese.
     */
    public const CHAR_CLASS = '\p{L}\p{N}';

    /**
     * Letters that render as nothing.
     *
     * The Hangul fillers are category Lo, so \p{L} admits them, and a slug
     * built from them is invisible in an admin list, a sitemap and a URL bar
     * alike — the trick behind invisible-username attacks. Unicode has a
     * Default_Ignorable_Code_Point property that would describe this exactly,
     * but PCRE does not implement it, so the four are named.
     */
    public const INVISIBLE_LETTERS = '\x{115F}\x{1160}\x{3164}\x{FFA0}';

    /**
     * Longest slug that can be stored (`posts.slug` is VARCHAR(1000)).
     */
    private const MAX_BYTES = 1000;

    /**
     * Validate a slug. Throws ValidationException if invalid.
     */
    public function validate(string $slug): void
    {
        if ($slug === '') {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Please enter a slug.']]
            );
        }

        if (strlen($slug) > self::MAX_BYTES) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Slug is too long.']]
            );
        }

        // Letters and digits from any script — a site in Japanese, Greek or
        // Russian should be able to use its own words in a URL, the way
        // WordPress has always allowed. Everything with meaning in a URL
        // (`?`, `#`, `%`, `.`, whitespace) stays out, so a stored slug never
        // has to be parsed to be understood.
        if (!preg_match('#^[' . self::CHAR_CLASS . '][' . self::CHAR_CLASS . '\-/]*$#u', $slug)) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Slug may only contain letters, digits, hyphens, and slashes.']]
            );
        }

        // Matched as codepoints, not bytes: a byte-wise search would see the
        // lead byte of U+3164 inside perfectly ordinary kana.
        if (preg_match('/[' . self::INVISIBLE_LETTERS . ']/u', $slug) === 1) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Slug must not contain invisible characters.']]
            );
        }

        // Case-folding is per-script: this lowercases Latin and Cyrillic and
        // leaves Japanese untouched. Uppercase is refused rather than
        // corrected because two slugs differing only in case would otherwise
        // be two rows serving one URL.
        if (mb_strtolower($slug, 'UTF-8') !== $slug) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Slug must be lowercase.']]
            );
        }

        // No consecutive slashes
        if (str_contains($slug, '//')) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Slug must not contain consecutive slashes.']]
            );
        }

        // Get top-level segment
        $topLevel = explode('/', $slug)[0];

        // Check minimum length for top-level
        if (self::isTooShort($topLevel)) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['The top-level slug must be at least 3 characters long.']]
            );
        }

        // Check reserved system routes
        if (in_array($topLevel, self::RESERVED_SLUGS, true)) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ["\"{$topLevel}\" is a system-reserved slug."]]
            );
        }

        // Check locale pattern (e.g., en, ja, pt-br) — reserved for Multilang module
        if (preg_match('/^[a-z]{2}(-[a-z]{2,})?$/', $topLevel)) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Slugs that look like language codes are not allowed (reserved for the Multilang module).']]
            );
        }
    }

    /**
     * Whether a string is worth looking a row up with.
     *
     * The routing layer decodes a percent-escaped request path, which means
     * arbitrary bytes chosen by an anonymous caller reach this point:
     * `/%FF%FE` decodes to invalid UTF-8, `/%00` to a NUL. Handing those to
     * the database is how a crafted URL turns into a 500 on PostgreSQL, whose
     * UTF-8 columns reject an invalid byte sequence outright. Screening here
     * keeps the decoded value from being trusted merely because it was
     * decoded.
     *
     * Deliberately *not* validate(): a lookup must answer 404, never a
     * validation error, and rows written by an older version are not required
     * to satisfy today's rules for reserved words, case or length.
     */
    public static function isResolvable(string $slug): bool
    {
        if ($slug === '' || strlen($slug) > self::MAX_BYTES) {
            return false;
        }

        // NUL and control characters have no place in a slug and are the part
        // of a decoded path most likely to confuse something downstream.
        if (preg_match('/[\x00-\x1F\x7F]/', $slug) === 1) {
            return false;
        }

        return mb_check_encoding($slug, 'UTF-8');
    }

    /**
     * Generate a slug from title, ensuring uniqueness in DB.
     */
    public function generateUnique(string $title, \PDO $pdo, ?string $excludeId = null): string
    {
        return $this->adoptUnique($this->titleToSlug($title), $pdo, $excludeId);
    }

    /**
     * Make an already-formed slug storable: pad a too-short top-level segment,
     * step around reserved routes, then make it unique.
     *
     * Separate from generateUnique() because the importer arrives with a slug
     * the source site already chose. Running that back through titleToSlug()
     * would strip the `/` out of a hierarchical page path — which validate()
     * accepts and the front controller resolves.
     */
    public function adoptUnique(string $base, \PDO $pdo, ?string $excludeId = null): string
    {
        // Length is checked on the top-level segment rather than the whole
        // string, because that is what validate() enforces: `a/bcd` is short
        // where it counts even though the slug as a whole is not.
        if (self::isTooShort(explode('/', $base)[0])) {
            $base = 'page-' . $base;
        }

        // Check system reservations but allow retrying with suffix
        $topLevel = explode('/', $base)[0];
        if (in_array($topLevel, self::RESERVED_SLUGS, true) || preg_match('/^[a-z]{2}(-[a-z]{2,})?$/', $topLevel)) {
            $base = 'p-' . $base;
        }

        return $this->ensureUnique($base, $pdo, $excludeId);
    }

    /**
     * The three-character floor exists so a slug cannot look like a language
     * code (`en`, `ja`) or crowd the short reserved routes. Neither hazard
     * exists outside ASCII, and imposing it there would reject ordinary words
     * — 会社 is two characters and a perfectly good slug — so the rule is
     * applied only where it has something to protect.
     */
    private static function isTooShort(string $segment): bool
    {
        // \z, not $: `$` would also match before a trailing newline.
        return preg_match('/^[\x00-\x7F]*\z/', $segment) === 1 && strlen($segment) < 3;
    }

    private function titleToSlug(string $title): string
    {
        $slug = mb_strtolower($title, 'UTF-8');
        // Keep base letters and digits from any script; everything else is a
        // separator. A Japanese title used to be stripped to nothing here and
        // fall through to a timestamp.
        $slug = preg_replace('/[^' . self::CHAR_CLASS . '\s\-]+/u', '', $slug) ?? '';
        $slug = preg_replace('/[' . self::INVISIBLE_LETTERS . ']+/u', '', $slug) ?? '';
        $slug = preg_replace('/[\s\-]+/u', '-', trim($slug)) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'post-' . date('YmdHis');
        }

        return $slug;
    }

    /**
     * Append `-2`, `-3`, … until the slug is free. Public because the importer
     * needs "make *this* slug unique" rather than "derive a slug from a title".
     */
    public function ensureUnique(string $base, \PDO $pdo, ?string $excludeId = null): string
    {
        $candidate = $base;
        $counter   = 2;

        while (true) {
            $sql    = 'SELECT id FROM posts WHERE slug = ?';
            $params = [$candidate];
            if ($excludeId !== null) {
                $sql    .= ' AND id != ?';
                $params[] = $excludeId;
            }
            $stmt = $pdo->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            if ($stmt->fetch() === false) {
                return $candidate;
            }
            $candidate = $base . '-' . $counter;
            $counter++;
        }
    }
}
