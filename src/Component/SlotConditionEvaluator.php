<?php
declare(strict_types=1);

namespace TypeDock\Component;

/**
 * Evaluate a slot placement's conditions JSON against the current render
 * context. All declared conditions are AND-combined; each condition whose
 * value is an array is matched with OR semantics within the array.
 *
 * Supported keys (extensible — unknown keys are treated as "does not match"):
 *   - page_type         : ['post'] / ['page']
 *   - category          : ['slug', ...]  (post must share at least one)
 *   - category_not      : ['slug', ...]  (post must not be in any)
 *   - is_home           : true
 *   - layout            : ['slug', ...]
 *   - route_type        : ['single', 'archive', 'search', 'home']
 *
 * Null / empty / missing conditions evaluate to true (render always).
 */
class SlotConditionEvaluator
{
    /**
     * @param array<string, mixed>|null $conditions  Already-decoded conditions
     *                                               (pass null for "no conditions").
     */
    public function evaluate(?array $conditions, RenderContext $ctx): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $key => $expected) {
            if (!$this->matchOne($key, $expected, $ctx)) {
                return false;
            }
        }
        return true;
    }

    private function matchOne(string $key, mixed $expected, RenderContext $ctx): bool
    {
        return match ($key) {
            'page_type'    => $this->anyMatches($expected, (string) ($ctx->pageType ?? '')),
            'layout'       => $this->anyMatches($expected, (string) ($ctx->page['layout'] ?? '')),
            'route_type'   => $this->anyMatches($expected, (string) ($ctx->routeType ?? '')),
            'is_home'      => ((bool) $expected) === ($this->isHome($ctx)),
            'category'     => $this->matchesAnyCategory($expected, $ctx),
            'category_not' => !$this->matchesAnyCategory($expected, $ctx),
            default        => true,  // Unknown keys are ignored rather than failing the match.
        };
    }

    /**
     * @param mixed $list
     */
    private function anyMatches(mixed $list, string $actual): bool
    {
        if ($actual === '') {
            return false;
        }
        if (is_array($list)) {
            foreach ($list as $candidate) {
                if ((string) $candidate === $actual) {
                    return true;
                }
            }
            return false;
        }
        return (string) $list === $actual;
    }

    /**
     * @param mixed $list  Expected slug(s) — array or scalar.
     */
    private function matchesAnyCategory(mixed $list, RenderContext $ctx): bool
    {
        $pageId = $ctx->page['id'] ?? null;
        if ($pageId === null) {
            return false;
        }
        $slugs = $this->categorySlugsFor((string) $pageId);
        if ($slugs === []) {
            return false;
        }
        $needle = is_array($list) ? $list : [$list];
        foreach ($needle as $want) {
            if (in_array((string) $want, $slugs, true)) {
                return true;
            }
        }
        return false;
    }

    /** @var array<string, array<string>> */
    private array $categorySlugCache = [];

    /**
     * @return array<string>
     */
    private function categorySlugsFor(string $pageId): array
    {
        if (isset($this->categorySlugCache[$pageId])) {
            return $this->categorySlugCache[$pageId];
        }
        $slugs = [];
        try {
            $pdo  = \Flight::db();
            $stmt = $pdo->prepare(
                'SELECT c.slug FROM categories c
                 JOIN page_categories pc ON pc.category_id = c.id
                 WHERE pc.page_id = ?'
            );
            $stmt->execute([$pageId]);
            foreach ($stmt->fetchAll() as $row) {
                $slugs[] = (string) $row['slug'];
            }
        } catch (\Throwable) {
            // fall through — empty list means no category conditions match.
        }
        return $this->categorySlugCache[$pageId] = $slugs;
    }

    private function isHome(RenderContext $ctx): bool
    {
        if ($ctx->routeType === 'home') {
            return true;
        }
        $url = $ctx->currentUrl === '' ? '/' : $ctx->currentUrl;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        return $path === '/' || $path === '';
    }
}
