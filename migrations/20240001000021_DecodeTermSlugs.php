<?php

declare(strict_types=1);

use TypeDock\Core\Migration\Migration;
use TypeDock\Core\Migration\Schema;

/**
 * Store category and tag slugs decoded.
 *
 * TermSlugger used to return rawurlencode($slug), so a Japanese category was
 * written as `%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B`. No request could match
 * that: Flight urldecodes route parameters, so `/category/お知らせ` reached the
 * controller as `お知らせ` and the lookup came back empty. Every non-ASCII term
 * archive has therefore been a 404 since the feature shipped.
 *
 * Percent-encoding now happens on the way out (slug_path()), so the stored
 * form has to be decoded for those rows to become reachable. ASCII slugs are
 * their own encoding and are left exactly as they are — the WHERE clause only
 * picks up rows that actually contain an escape.
 */
final class DecodeTermSlugs extends Migration
{
    public function up(Schema $schema): void
    {
        foreach (['categories', 'tags'] as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $this->decode($schema->pdo, $table);
        }
    }

    private function decode(\PDO $pdo, string $table): void
    {
        // Filtered in PHP rather than with `LIKE '%\%%' ESCAPE '\'`, whose
        // escaping differs between the three supported drivers. Term tables
        // hold hundreds of rows, so the scan costs nothing.
        $stmt = $pdo->query("SELECT id, slug, locale FROM {$table}");
        $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $update = $pdo->prepare("UPDATE {$table} SET slug = ? WHERE id = ?");
        $taken  = [];

        foreach ($rows as $row) {
            $slug    = (string) $row['slug'];
            $decoded = rawurldecode($slug);
            $locale  = (string) $row['locale'];
            if ($decoded === $slug) {
                continue;
            }

            // The old TermSlugger encoded every byte but did not otherwise
            // enforce today's term alphabet. Decoding `%00`, invalid UTF-8 or
            // `%2F` straight into the column can respectively abort a
            // PostgreSQL migration or create a value the one-segment term
            // route can never resolve. Leave such legacy values encoded; the
            // new output helper can still address them via `%25...`.
            if (!$this->isSafeTermSlug($decoded)) {
                continue;
            }

            // (slug, locale) is unique. A collision here would mean the site
            // already has a term holding the decoded name *in this locale*.
            // The same slug in another locale is valid and must not prevent
            // this row from becoming reachable.
            $key = $locale . "\0" . $decoded;
            if (isset($taken[$key]) || $this->exists($pdo, $table, $decoded, $locale)) {
                continue;
            }

            $taken[$key] = true;
            $update->execute([$decoded, $row['id']]);
        }
    }

    private function exists(\PDO $pdo, string $table, string $slug, string $locale): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE slug = ? AND locale = ? LIMIT 1");
        $stmt->execute([$slug, $locale]);

        return $stmt->fetchColumn() !== false;
    }

    private function isSafeTermSlug(string $slug): bool
    {
        if ($slug === '' || !mb_check_encoding($slug, 'UTF-8') || mb_strlen($slug, 'UTF-8') > 255) {
            return false;
        }

        if (preg_match(
            '/^[' . \TypeDock\Content\SlugValidator::CHAR_CLASS . '-]+\z/u',
            $slug,
        ) !== 1) {
            return false;
        }

        if (preg_match(
            '/[' . \TypeDock\Content\SlugValidator::INVISIBLE_LETTERS . ']/u',
            $slug,
        ) === 1) {
            return false;
        }

        return mb_strtolower($slug, 'UTF-8') === $slug;
    }
}
