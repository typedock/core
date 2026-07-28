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
        $stmt = $pdo->query("SELECT id, slug FROM {$table}");
        $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $update = $pdo->prepare("UPDATE {$table} SET slug = ? WHERE id = ?");
        $taken  = [];

        foreach ($rows as $row) {
            $slug    = (string) $row['slug'];
            $decoded = rawurldecode($slug);
            if ($decoded === $slug) {
                continue;
            }

            // (slug, locale) is unique. A collision here would mean the site
            // already has a term holding the decoded name, so the encoded row
            // is the dead one — leave it rather than fail the migration and
            // block the upgrade over a duplicate nobody could reach.
            if (isset($taken[$decoded]) || $this->exists($pdo, $table, $decoded)) {
                continue;
            }

            $taken[$decoded] = true;
            $update->execute([$decoded, $row['id']]);
        }
    }

    private function exists(\PDO $pdo, string $table, string $slug): bool
    {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);

        return $stmt->fetchColumn() !== false;
    }
}
