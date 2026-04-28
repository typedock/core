<?php
declare(strict_types=1);

namespace TypeDock\Frontend;

use TypeDock\Component\RenderContext;
use TypeDock\Core\PaginationData;
use TypeDock\ExternalSource\ExternalSourceTemplateRenderer;
use TypeDock\Theme\TemplateResolver;

final class ExternalSourceFrontendController
{
    private const PER_PAGE = 10;

    public function tryRenderPath(string $slug): bool
    {
        $parts = array_values(array_filter(explode('/', trim($slug, '/')), fn (string $v): bool => $v !== ''));
        if ($parts === []) {
            return false;
        }

        $source = \Flight::external_sources()->findActiveBySlug($parts[0]);
        if ($source === null) {
            return false;
        }

        if (count($parts) === 1) {
            $this->renderList($source);
            return true;
        }

        if (($parts[1] ?? '') === 'page' && isset($parts[2]) && ctype_digit($parts[2])) {
            $this->renderList($source, max(1, (int) $parts[2]));
            return true;
        }

        $this->renderDetail($source, implode('/', array_slice($parts, 1)));
        return true;
    }

    /**
     * @param array<string, mixed> $source
     */
    public function renderList(array $source, int $page = 1, bool $isHome = false): void
    {
        $result = \Flight::external_sources()->fetchList($source, self::PER_PAGE, $page);
        $baseUrl = $isHome ? '/' : '/' . trim((string) $source['slug'], '/');

        $this->setPageContext(null, 'external_source', 'archive');
        $resolver = new TemplateResolver(TYPEDOCK_ROOT . '/themes', \Flight::latte()->getActiveTheme());
        $this->renderLatte($resolver->resolveExternalSourceList($source, $isHome), [
            'source' => (object) $source,
            'items' => $result['items'],
            'posts' => $result['items'],
            'pagination' => new PaginationData(
                current: $page,
                totalPages: (int) ceil(((int) ($result['total'] ?: 1)) / self::PER_PAGE),
                perPage: self::PER_PAGE,
                totalItems: (int) $result['total'],
                baseUrl: $baseUrl,
            ),
            'breadcrumbs' => [],
            'body_class' => $isHome ? 'home external-source external-source-' . $source['slug'] : 'archive external-source external-source-' . $source['slug'],
            'source_stale' => $result['stale'],
            'source_error' => $result['error'],
        ]);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function renderDetail(array $source, string $slug): void
    {
        $result = \Flight::external_sources()->fetchItem($source, $slug);
        $item = $result['item'];
        if (!$item instanceof \stdClass) {
            throw new \TypeDock\Exception\NotFoundException('External Source item not found: ' . $slug);
        }

        $body = (new ExternalSourceTemplateRenderer())->render((string) ($source['detail_template'] ?? ''), $item);
        $page = (object) [
            'id' => $item->id,
            'slug' => $item->slug,
            'url' => $item->url,
            'title' => $item->title,
            'excerpt' => $item->excerpt,
            'renderedBody' => $body,
            'publishedAt' => $item->publishedAt,
            'author' => (object) ['name' => '', 'slug' => ''],
            'categories' => [],
            'tags' => $item->tags,
            'thumbnail' => $item->thumbnail,
            'thumbnailAlt' => $item->thumbnailAlt,
            'source' => (object) $source,
            'resource' => $item,
        ];

        $this->setPageContext(['id' => $item->id, 'post_type' => 'external_source', 'slug' => $item->slug], 'external_source', 'single');
        $resolver = new TemplateResolver(TYPEDOCK_ROOT . '/themes', \Flight::latte()->getActiveTheme());
        $this->renderLatte($resolver->resolveExternalSourceDetail($source), [
            'source' => (object) $source,
            'resource' => $item,
            'page' => $page,
            'breadcrumbs' => [],
            'body_class' => 'single external-source external-source-' . $source['slug'],
            'source_stale' => $result['stale'],
            'source_error' => $result['error'],
        ]);
    }

    private function setPageContext(?array $page, string $contextType, string $routeType): void
    {
        \Flight::set('typedock.page_context', [
            'page' => $page,
            'context_type' => $contextType,
            'term' => null,
            'post_type' => 'external_source',
            'route_type' => $routeType,
        ]);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function renderLatte(string $template, array $vars): void
    {
        $vars = array_merge([
            'site' => new \TypeDock\Content\SiteService(\Flight::db()),
            'theme' => $this->themeObject(),
            'themeStyle' => \Flight::theme_style(),
            'cms' => $this->cmsObject(),
            'currentUrl' => (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            'fetch' => new \stdClass(),
        ], $vars);

        \Flight::latte()->render($template, $vars);
    }

    private function themeObject(): \TypeDock\Theme\ThemeContext
    {
        $activeTheme = \Flight::latte()->getActiveTheme();
        return new \TypeDock\Theme\ThemeContext(
            url: rtrim((string) config('app.url', ''), '/') . '/themes/' . $activeTheme,
            name: $activeTheme,
            settings: \Flight::theme_settings(),
        );
    }

    private function cmsObject(): object
    {
        return new class {
            public function hasModule(string $name): bool
            {
                return match ($name) {
                    'Collection' => (bool) env('MODULE_COLLECTION', false),
                    'Backup' => (bool) env('MODULE_BACKUP', false),
                    default => false,
                };
            }
        };
    }
}
