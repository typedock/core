<?php
declare(strict_types=1);

namespace TypeDock\Import;

use TypeDock\Content\PostService;
use TypeDock\Content\TiptapMarkdownRenderer;

/**
 * Points body links at the imported copies of the pages they used to link to.
 *
 * The official WordPress importer never does this, which is why migrated
 * sites keep quietly sending readers back to the old domain for years.
 *
 * It has to run *after* every document has landed, not while parsing:
 * whether a link's target became a post or a page decides its URL prefix, and
 * the writer may have had to change a slug (non-ASCII permalinks, reserved
 * words, collisions). Both are only knowable once the rows exist. The pass is
 * targeted — only posts whose body actually mentions the old host are touched,
 * so it is not a rewrite of the whole site.
 */
final class LinkRewriter
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @return int Number of posts whose body changed.
     */
    public function rewriteBatch(string $batchId, string $sourceSiteUrl): int
    {
        $host = parse_url($sourceSiteUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return 0;
        }

        $map = $this->buildUrlMap($batchId);
        if ($map === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, body FROM posts WHERE import_batch_id = ? AND body LIKE ?'
        );
        $stmt->execute([$batchId, '%' . $host . '%']);

        $update  = $this->pdo->prepare('UPDATE posts SET body = ?, body_markdown = ? WHERE id = ?');
        $changed = 0;

        foreach ($stmt->fetchAll() as $row) {
            $body = (string) $row['body'];
            $next = $this->rewrite($body, $host, $map);
            if ($next === $body) {
                continue;
            }
            $update->execute([$next, TiptapMarkdownRenderer::render($next) ?: null, $row['id']]);
            $changed++;
        }

        return $changed;
    }

    /**
     * Old permalink (normalised) => new site-relative path.
     *
     * @return array<string, string>
     */
    private function buildUrlMap(string $batchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug, post_type, external_url FROM posts
              WHERE import_batch_id = ? AND external_url IS NOT NULL'
        );
        $stmt->execute([$batchId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $key = self::normalise((string) $row['external_url']);
            if ($key === '') {
                continue;
            }
            $map[$key] = (string) $row['post_type'] === PostService::TYPE_PAGE
                ? '/' . $row['slug']
                : post_path((string) $row['slug']);
        }

        return $map;
    }

    /**
     * Replace every recognised form of a known old URL inside the raw JSON
     * body.
     *
     * Operating on the encoded JSON rather than the decoded tree is
     * deliberate: a URL can appear in a link mark's href, an image src, or as
     * plain text a reader can see, and all three should move. The forms below
     * are the ones that actually turn up in exported content.
     *
     * @param array<string, string> $map
     */
    private function rewrite(string $body, string $host, array $map): string
    {
        // `(?:\\?/){2}` covers both `//host` and the `\/\/host` that json_encode
        // produces, since the match runs against the encoded body rather than a
        // decoded tree — a URL can sit in a link href, an image src or visible
        // text, and all three should move.
        $pattern = '#(?:https?:)?(?:\\\\?/){2}' . preg_quote($host, '#') . '[^"\s<>]*#i';

        return preg_replace_callback(
            $pattern,
            static function (array $m) use ($map): string {
                $candidate = self::normalise(str_replace('\\/', '/', $m[0]));

                return $map[$candidate] ?? $m[0];
            },
            $body
        ) ?? $body;
    }

    /**
     * Reduce a URL to the part that identifies the document: no scheme, no
     * host, no trailing slash, no query or fragment. `?p=123` survives as part
     * of the query only when there is no path, which is exactly the case where
     * it *is* the identifier.
     */
    private static function normalise(string $url): string
    {
        $url   = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($url);
        if ($parts === false) {
            return '';
        }

        $path  = rtrim((string) ($parts['path'] ?? ''), '/');
        $query = (string) ($parts['query'] ?? '');

        if ($path === '' && $query !== '') {
            return '?' . $query;
        }

        return $path;
    }
}
