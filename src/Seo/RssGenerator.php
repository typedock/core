<?php
declare(strict_types=1);

namespace TypeDock\Seo;

class RssGenerator
{
    private string $siteUrl;
    private string $siteName;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->siteUrl  = rtrim((string) config('app.url', 'http://localhost'), '/');
        $this->siteName = (string) config('app.name', 'TypeDock');
    }

    /**
     * Generate RSS 2.0 feed.
     */
    public function generate(int $limit = 20): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.id, p.slug, p.title, p.excerpt, p.page_type, p.published_at, p.updated_at,
                    u.name as author_name
             FROM pages p
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.page_type = 'post' AND p.status = 'published'
             ORDER BY p.published_at DESC
             LIMIT ?"
        );
        $stmt->execute([$limit]);
        $posts = $stmt->fetchAll();

        $feedUrl   = $this->siteUrl . '/feed';
        $lastBuild = !empty($posts) ? date(DATE_RSS, strtotime((string) $posts[0]['published_at'])) : date(DATE_RSS);
        $siteName  = htmlspecialchars($this->siteName, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        $siteUrl   = htmlspecialchars($this->siteUrl, ENT_XML1 | ENT_COMPAT, 'UTF-8');

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">' . "\n";
        $xml .= "<channel>\n";
        $xml .= "  <title>{$siteName}</title>\n";
        $xml .= "  <link>{$siteUrl}</link>\n";
        $xml .= "  <description>Feed for {$siteName}</description>\n";
        $xml .= "  <language>ja</language>\n";
        $xml .= "  <lastBuildDate>{$lastBuild}</lastBuildDate>\n";
        $xml .= '  <atom:link href="' . htmlspecialchars($feedUrl, ENT_XML1 | ENT_COMPAT, 'UTF-8') . '" rel="self" type="application/rss+xml"/>' . "\n";

        foreach ($posts as $post) {
            $title   = htmlspecialchars((string) $post['title'], ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $link    = htmlspecialchars($this->siteUrl . post_path((string) $post['slug']), ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $desc    = htmlspecialchars((string) ($post['excerpt'] ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
            $pubDate = date(DATE_RSS, strtotime((string) $post['published_at']));
            $guid    = $link;

            $xml .= "  <item>\n";
            $xml .= "    <title>{$title}</title>\n";
            $xml .= "    <link>{$link}</link>\n";
            $xml .= "    <description>{$desc}</description>\n";
            $xml .= "    <pubDate>{$pubDate}</pubDate>\n";
            $xml .= "    <guid isPermaLink=\"true\">{$guid}</guid>\n";
            if (!empty($post['author_name'])) {
                $xml .= '    <author>' . htmlspecialchars((string) $post['author_name'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . "</author>\n";
            }
            $xml .= "  </item>\n";
        }

        $xml .= "</channel>\n</rss>";
        return $xml;
    }
}
