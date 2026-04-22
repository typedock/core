<?php
declare(strict_types=1);

namespace TypeDock\Seo;

class BreadcrumbBuilder
{
    public function __construct(private readonly \PDO $pdo) {}

    /**
     * Breadcrumb for `/{posts_archive_slug}` (the posts archive accessed by
     * its own URL, not as the site root). When the site has `home_mode =
     * 'archive'`, the caller suppresses breadcrumbs entirely — this method
     * is only reached in 'page' mode, where the archive is a real second
     * page below Home.
     *
     * @return array<BreadcrumbItem>
     */
    public function forBlogIndex(): array
    {
        return [
            new BreadcrumbItem('Home', '/', false),
            new BreadcrumbItem($this->postsLabel(), $this->postsPath(), true),
        ];
    }

    /**
     * @param  array<string, mixed> $post
     * @return array<BreadcrumbItem>
     */
    public function forPost(array $post): array
    {
        $crumbs = [
            new BreadcrumbItem('Home', '/', false),
            new BreadcrumbItem($this->postsLabel(), $this->postsPath(), false),
        ];

        $primary = $this->primaryCategory((string) $post['id']);
        if ($primary !== null) {
            $crumbs[] = new BreadcrumbItem(
                (string) $primary['name'],
                '/category/' . $primary['slug'],
                false,
            );
        }

        $crumbs[] = new BreadcrumbItem((string) $post['title'], '', true);
        return $crumbs;
    }

    /**
     * @param  array<string, mixed> $page
     * @return array<BreadcrumbItem>
     */
    public function forPage(array $page): array
    {
        $crumbs = [new BreadcrumbItem('Home', '/', false)];

        foreach ($this->parentChain($page) as $parent) {
            $crumbs[] = new BreadcrumbItem(
                (string) $parent['title'],
                '/' . ltrim((string) $parent['slug'], '/'),
                false,
            );
        }

        $crumbs[] = new BreadcrumbItem((string) $page['title'], '', true);
        return $crumbs;
    }

    /**
     * @param  array<string, mixed> $category
     * @return array<BreadcrumbItem>
     */
    public function forCategoryArchive(array $category): array
    {
        return [
            new BreadcrumbItem('Home', '/', false),
            new BreadcrumbItem($this->postsLabel(), $this->postsPath(), false),
            new BreadcrumbItem((string) $category['name'], '', true),
        ];
    }

    /**
     * @param  array<string, mixed> $tag
     * @return array<BreadcrumbItem>
     */
    public function forTagArchive(array $tag): array
    {
        return [
            new BreadcrumbItem('Home', '/', false),
            new BreadcrumbItem('Tag: ' . (string) $tag['name'], '', true),
        ];
    }

    /**
     * @return array<BreadcrumbItem>
     */
    public function forSearch(string $query): array
    {
        $label = $query === '' ? 'Search' : 'Search results for "' . $query . '"';
        return [
            new BreadcrumbItem('Home', '/', false),
            new BreadcrumbItem($label, '', true),
        ];
    }

    private function postsLabel(): string
    {
        return (string) site_option('site.posts_archive_label', 'Blog');
    }

    private function postsPath(): string
    {
        return '/' . posts_archive_slug();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function primaryCategory(string $pageId): ?array
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT c.id, c.slug, c.name FROM categories c
                 JOIN page_categories pc ON pc.category_id = c.id
                 WHERE pc.page_id = ? ORDER BY c.name ASC LIMIT 1"
            );
            $stmt->execute([$pageId]);
            $row = $stmt->fetch();
            return $row !== false ? $row : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Walk pages.parent_id from root downward (exclusive of the page itself).
     *
     * @param  array<string, mixed> $page
     * @return array<array<string, mixed>>
     */
    private function parentChain(array $page): array
    {
        $chain    = [];
        $parentId = $page['parent_id'] ?? null;
        $seen     = [];

        while ($parentId !== null && !isset($seen[$parentId])) {
            $seen[$parentId] = true;
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT id, title, slug, parent_id FROM pages WHERE id = ? LIMIT 1'
                );
                $stmt->execute([$parentId]);
                $row = $stmt->fetch();
            } catch (\Throwable) {
                break;
            }
            if ($row === false) {
                break;
            }
            array_unshift($chain, $row);
            $parentId = $row['parent_id'] ?? null;
        }

        return $chain;
    }
}
