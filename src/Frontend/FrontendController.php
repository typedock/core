<?php
declare(strict_types=1);

namespace TypeDock\Frontend;

use TypeDock\Content\BlockRenderer;
use TypeDock\Component\ComponentRenderer;
use TypeDock\Content\PageService;
use TypeDock\Content\CategoryService;
use TypeDock\Content\TagService;
use TypeDock\Core\PaginationData;
use TypeDock\Seo\BreadcrumbBuilder;
use TypeDock\Seo\SeoService;
use TypeDock\Theme\TemplateResolver;

class FrontendController
{
    private const POSTS_PER_PAGE = 10;

    /**
     * Dispatch the site root (`/`) based on site.home_mode. Also handles
     * `/page/N` pagination — when the home is the posts archive, pagination
     * should work from the root URL; when home is a static page the
     * controller just renders that page and $page is ignored.
     */
    public function homepage(int $page = 1): void
    {
        $mode = (string) site_option('site.home_mode', 'archive');

        if ($mode === 'page') {
            $pageId  = (string) site_option('site.home_page_id', '');
            $pageRow = $pageId !== '' ? $this->getPageById($pageId) : null;
            // Fall back to the legacy behaviour (Page with slug "") if the
            // configured home page is missing/unpublished, so operators don't
            // get a 404 the moment they delete a page from under themselves.
            $pageRow ??= $this->getPageBySlug('');

            if ($pageRow !== null) {
                $this->renderPage($pageRow, isHome: true);
                return;
            }
            // No home page available at all — degrade to archive so `/` stays
            // navigable rather than 404ing on a fresh install.
        }

        $this->blogIndex($page, isHome: true);
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

    public function blogIndex(int $page = 1, bool $isHome = false): void
    {
        $pageService = new PageService(\Flight::db());
        $result      = $pageService->list([
            'page_type' => 'post',
            'status'    => 'published',
            'page'      => $page,
            'per_page'  => self::POSTS_PER_PAGE,
        ]);

        $postsSlug  = posts_archive_slug();
        $routeType  = $isHome ? 'home' : 'archive';
        $paginationBase = $isHome ? '/' : '/' . $postsSlug;

        $this->setPageContext(null, 'archive', null, $routeType);
        $resolver = new TemplateResolver(TYPEDOCK_ROOT . '/themes', \Flight::latte()->getActiveTheme());
        $builder  = new BreadcrumbBuilder(\Flight::db());

        $this->renderLatte($resolver->resolveArchive(null, '', $isHome), [
            'posts'       => $result['items'],
            'pagination'  => new PaginationData(
                current: $page,
                totalPages: (int) ceil(($result['total'] ?: 1) / self::POSTS_PER_PAGE),
                perPage: self::POSTS_PER_PAGE,
                totalItems: (int) $result['total'],
                baseUrl: $paginationBase,
            ),
            'breadcrumbs' => $isHome ? [] : $builder->forBlogIndex(),
            'body_class'  => $isHome ? 'home archive blog-archive' : 'archive blog-archive',
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

        $this->setPageContext(null, 'archive', $category, 'archive');
        $resolver = new TemplateResolver(TYPEDOCK_ROOT . '/themes', \Flight::latte()->getActiveTheme());
        $this->renderLatte($resolver->resolveArchive($category, 'category'), [
            'category'    => $category,
            'posts'       => $posts,
            'pagination'  => new PaginationData(
                current: $page,
                totalPages: (int) ceil(($total ?: 1) / $perPage),
                perPage: $perPage,
                totalItems: $total,
                baseUrl: '/category/' . $slug,
            ),
            'breadcrumbs' => (new BreadcrumbBuilder(\Flight::db()))->forCategoryArchive($category),
            'body_class'  => 'archive category-archive category-' . $slug,
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

        $this->setPageContext(null, 'archive', $tag, 'archive');
        $resolver = new TemplateResolver(TYPEDOCK_ROOT . '/themes', \Flight::latte()->getActiveTheme());
        $this->renderLatte($resolver->resolveArchive($tag, 'tag'), [
            'tag'         => $tag,
            'posts'       => $posts,
            'pagination'  => new PaginationData(
                current: $page,
                totalPages: (int) ceil(($total ?: 1) / $perPage),
                perPage: $perPage,
                totalItems: $total,
                baseUrl: '/tag/' . $slug,
            ),
            'breadcrumbs' => (new BreadcrumbBuilder(\Flight::db()))->forTagArchive($tag),
            'body_class'  => 'archive tag-archive tag-' . $slug,
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

        $this->setPageContext(null, 'search', null, 'search');
        $searchBase = '/search?q=' . rawurlencode($query);
        $this->renderLatte('layouts/search.latte', [
            'query'       => $query,
            'results'     => $results['items'],
            'pagination'  => new PaginationData(
                current: $page,
                totalPages: (int) ceil((($results['total'] ?: 1)) / self::POSTS_PER_PAGE),
                perPage: self::POSTS_PER_PAGE,
                totalItems: (int) $results['total'],
                baseUrl: $searchBase,
                useQueryString: true,
            ),
            'breadcrumbs' => (new BreadcrumbBuilder(\Flight::db()))->forSearch($query),
            'body_class'  => 'search-page',
        ]);
    }

    /**
     * @param  array<string, mixed> $page
     */
    private function renderPage(array $page, bool $isHome = false): void
    {
        $blockRenderer = new BlockRenderer(\Flight::component_renderer());
        $renderedBody  = $blockRenderer->render($page['body']);

        $pageType = (string) ($page['page_type'] ?? 'page');
        $resolver = new TemplateResolver(TYPEDOCK_ROOT . '/themes', \Flight::latte()->getActiveTheme());

        if (!empty($page['template'])) {
            // Explicit per-page template override still wins.
            $layout = (string) $page['template'];
        } elseif ($isHome) {
            $layout = $resolver->resolveHome($page);
        } elseif ($pageType === 'post') {
            $categories = (new PageService(\Flight::db()))->getCategories((string) $page['id']);
            $layout     = $resolver->resolvePost($page, $categories);
        } else {
            $layout = $resolver->resolvePage($page);
        }

        $breadcrumbBuilder = new BreadcrumbBuilder(\Flight::db());
        if ($isHome) {
            $breadcrumbs = [];
        } elseif ($pageType === 'post') {
            $breadcrumbs = $breadcrumbBuilder->forPost($page);
        } else {
            $breadcrumbs = $breadcrumbBuilder->forPage($page);
        }

        $this->setPageContext($page, $pageType, null, $isHome ? 'home' : 'single');
        $this->renderLatte($layout, [
            'page'        => $this->buildPageObject($page, $renderedBody),
            'seo'         => (new SeoService(\Flight::db()))->resolveForPage($page),
            'breadcrumbs' => $breadcrumbs,
            'body_class'  => $isHome
                ? 'home ' . ($pageType === 'post' ? 'single-post' : 'page')
                : ($pageType === 'post' ? 'single single-post' : 'page'),
        ]);
    }

    /**
     * @param  array<string, mixed> $page
     */
    private function buildPageObject(array $page, string $renderedBody): object
    {
        $prefix = ($page['page_type'] ?? '') === 'post' ? post_path() . '/' : '/';
        $base   = rtrim((string) config('app.url', ''), '/');
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
            'url'          => $base . $prefix . ltrim((string) $page['slug'], '/'),
        ];
    }

    public function renderErrorPage(string $type, string $message): void
    {
        $this->renderLatte("layouts/{$type}.latte", ['message' => $message]);
    }

    /**
     * Stash the current page row and its broad context type on Flight so
     * `{slot(...)}` and `{component(...)}` can feed a populated RenderContext
     * to data providers without each theme template having to pass the page
     * explicitly. Themes can still override by passing a second argument to
     * the slot function.
     *
     * @param array<string, mixed>|null $page
     * @param array<string, mixed>|null $term
     */
    private function setPageContext(
        ?array $page,
        string $contextType,
        ?array $term = null,
        ?string $routeType = null
    ): void {
        $pageType = $page !== null ? (string) ($page['page_type'] ?? '') : '';
        \Flight::set('typedock.page_context', [
            'page'         => $page,
            'context_type' => $contextType,
            'term'         => $term,
            'page_type'    => $pageType !== '' ? $pageType : null,
            'route_type'   => $routeType,
        ]);
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
            'themeStyle' => \Flight::theme_style(),
            'cms'        => $this->buildCmsObject(),
            'currentUrl' => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
        ], $vars);

        // Resolve theme.json `templates.{name}.fetch` for the template being
        // rendered so themes can ask for data (latest posts, categories, …)
        // without writing any PHP. The matching template entry is identified
        // by its `file` key, which is the theme-relative path.
        if (!isset($vars['fetch'])) {
            $vars['fetch'] = $this->resolveTemplateFetch($template);
        }

        \Flight::latte()->render($template, $vars);
        $this->emitPreviewBannerIfActive();
    }

    /**
     * Look up the fetch definition for the template being rendered and run
     * it through FetchResolver. Returns an empty object when the theme
     * doesn't declare any fetch for this template — keeps `$fetch->foo ??
     * null` idiomatic in templates.
     */
    private function resolveTemplateFetch(string $template): object
    {
        try {
            $loader = new \TypeDock\Theme\ThemeLoader();
            $active = \Flight::latte()->getActiveTheme();
            $config = $loader->loadThemeConfig($active);
            $templates = $config['templates'] ?? [];

            $needle = ltrim($template, '/');
            foreach ($templates as $def) {
                $file = ltrim((string) ($def['file'] ?? ''), '/');
                if ($file !== '' && ($file === $needle || 'layouts/' . $file === $needle || $file === 'layouts/' . $needle)) {
                    if (!empty($def['fetch']) && is_array($def['fetch'])) {
                        $stash = (array) (\Flight::get('typedock.page_context') ?? []);
                        $ctx   = new \TypeDock\Component\RenderContext(
                            locale: (string) config('app.locale', 'en'),
                            page: is_array($stash['page'] ?? null) ? $stash['page'] : null,
                            currentUrl: (string) ($_SERVER['REQUEST_URI'] ?? '/'),
                            contextType: (string) ($stash['context_type'] ?? ''),
                            term: is_array($stash['term'] ?? null) ? $stash['term'] : null,
                            pageType: is_string($stash['page_type'] ?? null) ? $stash['page_type'] : null,
                            routeType: is_string($stash['route_type'] ?? null) ? $stash['route_type'] : null,
                        );
                        return (new \TypeDock\Component\FetchResolver())->resolve($def['fetch'], [], $ctx);
                    }
                    break;
                }
            }
        } catch (\Throwable) {
            // Falls through to an empty object — fetch is always optional.
        }
        return new \stdClass();
    }

    /**
     * When the request resolved via an admin preview_theme override, append a
     * fixed-position banner so the previewing admin can tell this isn't the
     * live site and can jump back to theme management in one click. The banner
     * is only rendered if App::bootTheme() actually accepted the override
     * (which requires an admin session), so anonymous visitors never see it
     * even if they craft the query string themselves.
     */
    private function emitPreviewBannerIfActive(): void
    {
        $requested = isset($_GET['preview_theme']) ? (string) $_GET['preview_theme'] : '';
        if ($requested === '') {
            return;
        }
        $active = \Flight::latte()->getActiveTheme();
        if ($active !== $requested) {
            return;
        }
        $label = htmlspecialchars($active, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo <<<HTML
<div style="position:fixed;left:0;right:0;bottom:0;z-index:9999;background:#0f172a;color:#f8fafc;font:500 13px/1.4 system-ui,-apple-system,sans-serif;padding:10px 16px;display:flex;gap:12px;align-items:center;justify-content:center;box-shadow:0 -4px 20px rgba(0,0,0,.25)">
<span>Previewing theme: <strong>{$label}</strong></span>
<a href="/admin/themes" style="color:#f8fafc;background:rgba(255,255,255,.12);padding:4px 10px;border-radius:4px;text-decoration:none">Back to themes</a>
</div>
HTML;
    }

    private function buildSiteObject(): \TypeDock\Content\SiteService
    {
        return new \TypeDock\Content\SiteService(\Flight::db());
    }

    private function buildThemeObject(): \TypeDock\Theme\ThemeContext
    {
        $activeTheme = \Flight::latte()->getActiveTheme();
        return new \TypeDock\Theme\ThemeContext(
            url: rtrim((string) config('app.url', ''), '/') . '/themes/' . $activeTheme,
            name: $activeTheme,
            settings: \Flight::theme_settings(),
        );
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

    /**
     * Load a published page by id. Returns null when no published row exists,
     * which is what lets the home-page fallback kick in when an operator
     * deletes the Page they previously pointed `site.home_page_id` at.
     *
     * @return array<string, mixed>|null
     */
    private function getPageById(string $id): ?array
    {
        $pdo  = \Flight::db();
        $stmt = $pdo->prepare(
            "SELECT p.*, u.name as author_name FROM pages p
             LEFT JOIN users u ON u.id = p.author_id
             WHERE p.id = ? AND p.status = 'published' LIMIT 1"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}
