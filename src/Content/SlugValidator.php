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
     * Validate a slug. Throws ValidationException if invalid.
     */
    public function validate(string $slug): void
    {
        if ($slug === '') {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Please enter a slug.']]
            );
        }

        // Allow only lowercase alphanumeric, hyphens, and slashes
        if (!preg_match('#^[a-z0-9][a-z0-9\-/]*$#', $slug)) {
            throw new \TypeDock\Exception\ValidationException(
                ['slug' => ['Slug may only contain lowercase alphanumerics, hyphens, and slashes.']]
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
        if (strlen($topLevel) < 3) {
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
     * Generate a slug from title, ensuring uniqueness in DB.
     */
    public function generateUnique(string $title, \PDO $pdo, ?string $excludeId = null): string
    {
        $base = $this->titleToSlug($title);

        // Ensure minimum 3 chars
        if (strlen($base) < 3) {
            $base = 'page-' . $base;
        }

        // Check system reservations but allow retrying with suffix
        $topLevel = explode('/', $base)[0];
        if (in_array($topLevel, self::RESERVED_SLUGS, true) || preg_match('/^[a-z]{2}(-[a-z]{2,})?$/', $topLevel)) {
            $base = 'p-' . $base;
        }

        return $this->ensureUnique($base, $pdo, $excludeId);
    }

    private function titleToSlug(string $title): string
    {
        // Basic transliteration: romanize or use title as-is
        $slug = mb_strtolower($title, 'UTF-8');
        // Remove non-ASCII or convert spaces/special chars
        $slug = preg_replace('/[^a-z0-9\s\-]/u', '', $slug) ?? '';
        $slug = preg_replace('/[\s\-]+/', '-', trim($slug)) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'post-' . date('YmdHis');
        }

        return $slug;
    }

    private function ensureUnique(string $base, \PDO $pdo, ?string $excludeId = null): string
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
