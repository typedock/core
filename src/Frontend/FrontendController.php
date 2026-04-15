<?php
declare(strict_types=1);

namespace TypeDock\Frontend;

use TypeDock\Content\BlockRenderer;
use TypeDock\Component\ComponentRenderer;
use TypeDock\Content\PageService;
use TypeDock\Content\CategoryService;
use TypeDock\Content\TagService;

class FrontendController
{
    private const POSTS_PER_PAGE = 10;

    public function homepage(): void
    {
        $page = $this->getPageBySlug('');

        if ($page === null) {
            // Show blog as homepage if no explicit homepage
            $this->blogIndex();
            return;
        }

        $this->renderPage($page);
    }

    public function page(): void
    {
        $uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $slug = ltrim($uri, '/');

        if ($slug === '') {
            $this->homepage();
            return;
        }

        $page = $this->getPageBySlug($slug);
        if ($page === null) {
            throw new \TypeDock\Exception\NotFoundException("Page not found: {$slug}");
        }

        $this->renderPage($page);
    }

    public function blogIndex(int $page = 1): void
    {
        $pageService = new PageService(\Flight::db());
        $result      = $pageService->list([
            'page_type' => 'post',
            'status'    => 'published',
            'page'      => $page,
            'per_page'  => self::POSTS_PER_PAGE,
        ]);

        $this->renderLatte('layouts/archive.latte', [
            'posts'        => $result['items'],
            'total'        => $result['total'],
            'current_page' => $page,
            'per_page'     => self::POSTS_PER_PAGE,
            'body_class'   => 'archive blog-archive',
        ]);
    }

    public function blogPost(string $slug): void
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare(
            "SELECT p.*, u.name as author_name FROM pages p
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.slug = ? AND p.page_type = 'post' AND p.status = 'published' LIMIT 1"
        );
        $stmt->execute([$slug]);
        $page = $stmt->fetch();

        if ($page === false) {
            throw new \TypeDock\Exception\NotFoundException("Post not found: {$slug}");
        }

        $this->renderPage($page);
    }

    public function categoryArchive(string $slug, int $page = 1): void
    {
        $catService = new CategoryService(\Flight::db());
        $category   = $catService->findBySlug($slug);

        if ($category === null) {
            throw new \TypeDock\Exception\NotFoundException("Category not found: {$slug}");
        }

        $perPage = self::POSTS_PER_PAGE;
        $offset  = ($page - 1) * $perPage;

        $pdo  = \Flight::db();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM pages p
             JOIN page_categories pc ON pc.page_id = p.id
             WHERE pc.category_id = ? AND p.status = 'published'"
        );
        $stmt->execute([$category['id']]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT p.*, u.name as author_name FROM pages p
             LEFT JOIN users u ON u.id = p.author_id
             JOIN page_categories pc ON pc.page_id = p.id
             WHERE pc.category_id = ? AND p.status = 'published'
             ORDER BY p.published_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$category['id'], $perPage, $offset]);
        $posts = $stmt->fetchAll();

        $this->renderLatte('layouts/archive.latte', [
            'category'     => $category,
            'posts'        => $posts,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
            'body_class'   => 'archive category-archive category-' . $slug,
        ]);
    }

    public function tagArchive(string $slug, int $page = 1): void
    {
        $tagService = new TagService(\Flight::db());
        $tag        = $tagService->findBySlug($slug);

        if ($tag === null) {
            throw new \TypeDock\Exception\NotFoundException("Tag not found: {$slug}");
        }

        $perPage = self::POSTS_PER_PAGE;
        $offset  = ($page - 1) * $perPage;

        $pdo  = \Flight::db();
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM pages p
             JOIN page_tags pt ON pt.page_id = p.id
             WHERE pt.tag_id = ? AND p.status = 'published'"
        );
        $stmt->execute([$tag['id']]);
        $total = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT p.*, u.name as author_name FROM pages p
             LEFT JOIN users u ON u.id = p.author_id
             JOIN page_tags pt ON pt.page_id = p.id
             WHERE pt.tag_id = ? AND p.status = 'published'
             ORDER BY p.published_at DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$tag['id'], $perPage, $offset]);
        $posts = $stmt->fetchAll();

        $this->renderLatte('layouts/archive.latte', [
            'tag'          => $tag,
            'posts'        => $posts,
            'total'        => $total,
            'current_page' => $page,
            'per_page'     => $perPage,
            'body_class'   => 'archive tag-archive tag-' . $slug,
        ]);
    }

    public function search(): void
    {
        $query   = trim($_GET['q'] ?? '');
        $page    = max(1, (int) ($_GET['page'] ?? 1));
        $results = ['items' => [], 'total' => 0];

        if ($query !== '') {
            $results = \Flight::search()->search($query, [
                'page'     => $page,
                'per_page' => self::POSTS_PER_PAGE,
            ]);
        }

        $this->renderLatte('layouts/search.latte', [
            'query'        => $query,
            'results'      => $results['items'],
            'total'        => $results['total'],
            'current_page' => $page,
            'per_page'     => self::POSTS_PER_PAGE,
            'body_class'   => 'search-page',
        ]);
    }

    /**
     * @param  array<string, mixed> $page
     */
    private function renderPage(array $page): void
    {
        $blockRenderer = new BlockRenderer(\Flight::component_renderer());
        $renderedBody  = $blockRenderer->render($page['body']);

        $pageType = (string) ($page['page_type'] ?? 'page');
        $layout   = $pageType === 'post' ? 'layouts/single.latte' : 'layouts/page.latte';

        // Detect template override
        if (!empty($page['template'])) {
            $layout = $page['template'];
        }

        $this->renderLatte($layout, [
            'page'       => $this->buildPageObject($page, $renderedBody),
            'body_class' => $pageType === 'post' ? 'single single-post' : 'page',
        ]);
    }

    /**
     * @param  array<string, mixed> $page
     */
    private function buildPageObject(array $page, string $renderedBody): object
    {
        return (object) [
            'id'           => $page['id'],
            'slug'         => $page['slug'],
            'title'        => $page['title'],
            'renderedBody' => $renderedBody,
            'excerpt'      => $page['excerpt'],
            'pageType'     => $page['page_type'],
            'status'       => $page['status'],
            'publishedAt'  => $page['published_at'],
            'updatedAt'    => $page['updated_at'],
            'author'       => (object) ['name' => $page['author_name'] ?? null],
            'url'          => config('app.url', '') . '/' . ltrim((string) $page['slug'], '/'),
        ];
    }

    public function renderErrorPage(string $type, string $message): void
    {
        $this->renderLatte("layouts/{$type}.latte", ['message' => $message]);
    }

    /**
     * @param  array<string, mixed> $vars
     */
    private function renderLatte(string $template, array $vars = []): void
    {
        $siteData = $this->buildSiteObject();
        $vars     = array_merge([
            'site'       => $siteData,
            'theme'      => $this->buildThemeObject(),
            'cms'        => $this->buildCmsObject(),
            'currentUrl' => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
        ], $vars);

        \Flight::latte()->render($template, $vars);
    }

    private function buildSiteObject(): object
    {
        try {
            $stmt = \Flight::db()->prepare("SELECT key_name, value FROM site_options WHERE group_name IN ('general', 'seo')");
            $stmt->execute();
            $rows = $stmt->fetchAll();
            $opts = [];
            foreach ($rows as $row) {
                $opts[$row['key_name']] = json_decode((string) $row['value'], true);
            }
        } catch (\Throwable) {
            $opts = [];
        }

        return new class ($opts) {
            public function __construct(private array $opts) {}

            public function __get(string $name): mixed
            {
                return match ($name) {
                    'name' => $this->opts['site.name'] ?? config('app.name', 'TypeDock'),
                    'url'  => config('app.url', 'http://localhost'),
                    default => null,
                };
            }

            public function __isset(string $name): bool
            {
                return in_array($name, ['name', 'url'], true);
            }

            public function option(string $key): mixed
            {
                return $this->opts[$key] ?? null;
            }
        };
    }

    private function buildThemeObject(): object
    {
        $activeTheme = 'default';
        return (object) [
            'url'  => config('app.url', '') . '/themes/' . $activeTheme,
            'name' => $activeTheme,
        ];
    }

    private function buildCmsObject(): object
    {
        return new class {
            public function hasModule(string $name): bool
            {
                return (bool) config('modules.modules.' . $name, false);
            }
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPageBySlug(string $slug): ?array
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare(
            "SELECT p.*, u.name as author_name FROM pages p
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.slug = ? AND p.status = 'published' LIMIT 1"
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}
