<?php
declare(strict_types=1);

namespace TypeDock\Install;

use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use TypeDock\Content\TiptapMarkdownRenderer;

/**
 * Idempotent seeder for a "fresh-but-not-empty" content set: enough
 * categories / tags / posts / pages / menus to exercise every theme
 * layout (home, archive, single, page, search, category, tag, author).
 *
 * Used by:
 *   - `cli/seed.php` for the "I just installed, give me something to
 *     look at" flow,
 *   - `cli/install.php --with-demo` so the same single command bootstraps
 *     a runnable site,
 *   - the dev preview / AI iteration loop that needs reproducible content.
 *
 * Every insert checks for an existing row by slug (or by location for
 * menus) before creating, so re-running the seeder is a safe no-op when
 * the demo content is already present. Existing operator-authored content
 * is never modified.
 */
final class DemoSeeder
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return array<string, int>  per-resource count of rows actually created
     */
    public function seed(?string $authorId = null): array
    {
        $authorId ??= $this->firstAdminId();
        if ($authorId === null) {
            throw new RuntimeException('DemoSeeder needs at least one user — run install/createAdmin first.');
        }

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $created = [
            'categories' => 0,
            'tags'       => 0,
            'posts'      => 0,
            'pages'      => 0,
            'menus'      => 0,
            'menu_items' => 0,
        ];

        // --- Categories ---
        $catSpecs = [
            ['slug' => 'news',       'name' => 'News',       'description' => 'Site announcements and news.', 'sort' => 0],
            ['slug' => 'technology', 'name' => 'Technology', 'description' => 'Tools, code, and tech notes.', 'sort' => 1],
            ['slug' => 'lifestyle',  'name' => 'Lifestyle',  'description' => 'Slower posts on day-to-day topics.', 'sort' => 2],
        ];
        $categoryIds = [];
        foreach ($catSpecs as $spec) {
            $existing = $this->findIdBySlug('categories', $spec['slug']);
            if ($existing !== null) {
                $categoryIds[$spec['slug']] = $existing;
                continue;
            }
            $id = Uuid::uuid7()->toString();
            $this->pdo->prepare(
                'INSERT INTO categories (id, slug, name, description, sort_order, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$id, $spec['slug'], $spec['name'], $spec['description'], $spec['sort'], $now]);
            $categoryIds[$spec['slug']] = $id;
            $created['categories']++;
        }

        // --- Tags ---
        $tagSpecs = [
            ['slug' => 'featured', 'name' => 'Featured'],
            ['slug' => 'top',      'name' => 'Top'],
            ['slug' => 'popular',  'name' => 'Popular'],
        ];
        $tagIds = [];
        foreach ($tagSpecs as $spec) {
            $existing = $this->findIdBySlug('tags', $spec['slug']);
            if ($existing !== null) {
                $tagIds[$spec['slug']] = $existing;
                continue;
            }
            $id = Uuid::uuid7()->toString();
            $this->pdo->prepare('INSERT INTO tags (id, slug, name, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$id, $spec['slug'], $spec['name'], $now]);
            $tagIds[$spec['slug']] = $id;
            $created['tags']++;
        }

        // --- Posts ---
        $postSpecs = [
            ['slug' => 'welcome-to-typedock',         'title' => 'Welcome to TypeDock',         'lede' => "We're glad you're here. This is the first post on your new site.",      'days_ago' => 0, 'cats' => ['news'],                  'tags' => ['featured', 'top']],
            ['slug' => 'getting-started-with-themes', 'title' => 'Getting Started with Themes', 'lede' => 'A quick tour of the bundled themes and how theme settings drive them.', 'days_ago' => 1, 'cats' => ['technology'],            'tags' => ['top']],
            ['slug' => 'writing-your-first-post',     'title' => 'Writing Your First Post',     'lede' => 'How the Tiptap editor handles structured content and component blocks.', 'days_ago' => 2, 'cats' => ['technology', 'news'],   'tags' => ['featured']],
            ['slug' => 'organising-with-categories',  'title' => 'Organising with Categories',  'lede' => 'Use categories for your top-level sections and tags for cross-cutting topics.', 'days_ago' => 4, 'cats' => ['news'],         'tags' => ['popular']],
            ['slug' => 'a-note-on-images',            'title' => 'A Note on Images',            'lede' => 'Set an OG image once and every theme picks it up — cards, hero, and social previews.', 'days_ago' => 6, 'cats' => ['lifestyle'],   'tags' => ['popular']],
        ];

        foreach ($postSpecs as $spec) {
            $existing = $this->findIdBySlug('posts', $spec['slug']);
            if ($existing !== null) {
                continue;
            }
            $id  = Uuid::uuid7()->toString();
            $pub = (new DateTimeImmutable("-{$spec['days_ago']} day"))->format('Y-m-d H:i:s');
            $body = $this->tiptapBody([
                $spec['lede'],
                'This is sample content seeded by the TypeDock demo seeder. Edit or delete it from /admin/posts.',
            ]);
            $bodyMarkdown = TiptapMarkdownRenderer::render($body);
            $this->pdo->prepare(
                'INSERT INTO posts (id, slug, title, body, body_markdown, excerpt, post_type, status, locale, author_id, published_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, $spec['slug'], $spec['title'], $body, $bodyMarkdown, $spec['lede'], 'post', 'published', 'en', $authorId, $pub, $pub, $pub]);

            foreach ($spec['cats'] as $catSlug) {
                if (isset($categoryIds[$catSlug])) {
                    $this->pdo->prepare('INSERT INTO post_categories (post_id, category_id) VALUES (?, ?)')
                        ->execute([$id, $categoryIds[$catSlug]]);
                }
            }
            foreach ($spec['tags'] as $tagSlug) {
                if (isset($tagIds[$tagSlug])) {
                    $this->pdo->prepare('INSERT INTO post_tags (post_id, tag_id) VALUES (?, ?)')
                        ->execute([$id, $tagIds[$tagSlug]]);
                }
            }
            $created['posts']++;
        }

        // --- Pages ---
        $pageSpecs = [
            [
                'slug'    => 'about',
                'title'   => 'About',
                'excerpt' => 'A short description of who runs this site.',
                'paras'   => [
                    'Tell visitors who you are and what this site is about.',
                    'You can edit this page from /admin/pages — or delete it if you want to start from scratch.',
                ],
            ],
            [
                'slug'    => 'contact',
                'title'   => 'Contact',
                'excerpt' => 'How to get in touch.',
                'paras'   => [
                    'Reach out via email, social media, or a contact form.',
                    'If the Form plugin is enabled, you can drop a `{component(\'form\')}` block from the editor here.',
                ],
            ],
        ];
        $pageIds = [];
        foreach ($pageSpecs as $spec) {
            $existing = $this->findIdBySlug('posts', $spec['slug']);
            if ($existing !== null) {
                $pageIds[$spec['slug']] = $existing;
                continue;
            }
            $id = Uuid::uuid7()->toString();
            $body = $this->tiptapBody($spec['paras']);
            $bodyMarkdown = TiptapMarkdownRenderer::render($body);
            $this->pdo->prepare(
                'INSERT INTO posts (id, slug, title, body, body_markdown, excerpt, post_type, status, locale, author_id, published_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, $spec['slug'], $spec['title'], $body, $bodyMarkdown, $spec['excerpt'], 'page', 'published', 'en', $authorId, $now, $now, $now]);
            $pageIds[$spec['slug']] = $id;
            $created['pages']++;
        }

        // --- Menus ---
        $menuSpecs = [
            [
                'name'     => 'Header Navigation',
                'location' => 'header',
                'items'    => [
                    ['label' => 'Home',    'url' => '/',      'target_type' => 'custom'],
                    ['label' => 'Blog',    'url' => '/blog',  'target_type' => 'custom'],
                    ['label' => 'About',   'page' => 'about'],
                    ['label' => 'Contact', 'page' => 'contact'],
                ],
            ],
            [
                'name'     => 'Footer Links',
                'location' => 'footer',
                'items'    => [
                    ['label' => 'About',   'page' => 'about'],
                    ['label' => 'Contact', 'page' => 'contact'],
                ],
            ],
        ];
        foreach ($menuSpecs as $spec) {
            $existing = $this->findMenuId($spec['location']);
            if ($existing !== null) {
                continue;
            }
            $menuId = Uuid::uuid7()->toString();
            $this->pdo->prepare(
                'INSERT INTO menus (id, name, location, locale, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$menuId, $spec['name'], $spec['location'], 'en', $now, $now]);
            $created['menus']++;

            $sort = 0;
            foreach ($spec['items'] as $item) {
                $itemId = Uuid::uuid7()->toString();
                if (isset($item['page'])) {
                    $targetType = 'page';
                    $targetId   = $pageIds[$item['page']] ?? null;
                    $url        = $targetId !== null ? '/' . $item['page'] : '#';
                } else {
                    $targetType = $item['target_type'] ?? 'custom';
                    $targetId   = null;
                    $url        = $item['url'] ?? '#';
                }
                $this->pdo->prepare(
                    'INSERT INTO menu_items (id, menu_id, parent_id, label, url, target_type, target_id, sort_order, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$itemId, $menuId, null, $item['label'], $url, $targetType, $targetId, $sort, $now]);
                $created['menu_items']++;
                $sort++;
            }
        }

        return $created;
    }

    private function firstAdminId(): ?string
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM users WHERE role IN ('admin', 'administrator') ORDER BY created_at LIMIT 1"
        );
        $id = $stmt === false ? false : $stmt->fetchColumn();
        if ($id === false || $id === null) {
            // Fall back to "any user" so seeding still works on environments
            // where the role label diverged.
            $stmt = $this->pdo->query('SELECT id FROM users ORDER BY created_at LIMIT 1');
            $id = $stmt === false ? false : $stmt->fetchColumn();
        }
        return $id === false || $id === null ? null : (string) $id;
    }

    private function findIdBySlug(string $table, string $slug): ?string
    {
        $stmt = $this->pdo->prepare("SELECT id FROM {$table} WHERE slug = ? LIMIT 1");
        $stmt->execute([$slug]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    private function findMenuId(string $location): ?string
    {
        $stmt = $this->pdo->prepare('SELECT id FROM menus WHERE location = ? LIMIT 1');
        $stmt->execute([$location]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (string) $id;
    }

    /**
     * Build a minimal Tiptap doc from a list of paragraphs. The renderer
     * (TiptapRenderer) walks this JSON to emit HTML, so seed content has
     * to use the same structure operators get from the Tiptap editor.
     *
     * @param array<string> $paragraphs
     */
    private function tiptapBody(array $paragraphs): string
    {
        $content = [];
        foreach ($paragraphs as $text) {
            $content[] = [
                'type'    => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ];
        }
        return (string) json_encode([
            'type'    => 'doc',
            'content' => $content,
        ]);
    }
}
