<?php
declare(strict_types=1);

namespace TypeDock\Theme;

use Latte\Extension;
use TypeDock\Component\RenderContext;
use TypeDock\Component\SlotConditionEvaluator;

class CmsLatteExtension extends Extension
{
    /**
     * @return array<string, callable>
     */
    public function getFunctions(): array
    {
        return [
            'component' => [$this, 'renderComponent'],
            'slot'      => [$this, 'renderSlot'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return [];
    }

    /**
     * Render a component by type. The optional $ctxOverride lets a theme inject
     * page/context_type explicitly (e.g. `{component('author_profile', [], ['page' => $page])}`);
     * otherwise we fall back to the request-scoped stash set by FrontendController.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $ctxOverride
     */
    public function renderComponent(string $type, array $params = [], array $ctxOverride = []): string
    {
        try {
            $context = $this->buildContext($ctxOverride);
            return \Flight::component_renderer()->render($type, $params, $context);
        } catch (\Throwable $e) {
            if ((bool) config('app.debug', false)) {
                return '<!-- component error [' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ']: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->';
            }
            return '';
        }
    }

    /**
     * Render a configurable slot (pulls components from slot_placements table).
     *
     * The optional $ctxOverride lets theme authors pass the current page
     * explicitly — `{slot('after_content', ['page' => $page])}` — while
     * existing `{slot('sidebar')}` calls continue to work via the
     * FrontendController stash.
     *
     * @param array<string, mixed> $ctxOverride
     */
    public function renderSlot(string $slotName, array $ctxOverride = []): string
    {
        try {
            $pdo  = \Flight::db();
            $stmt = $pdo->prepare(
                'SELECT component_type, params, conditions FROM slot_placements
                 WHERE slot_name = ?
                 ORDER BY sort_order ASC'
            );
            $stmt->execute([$slotName]);
            $placements = $stmt->fetchAll();

            $context   = $this->buildContext($ctxOverride);
            $renderer  = \Flight::component_renderer();
            $evaluator = new SlotConditionEvaluator();

            $html = '';
            foreach ($placements as $placement) {
                $conditions = !empty($placement['conditions'])
                    ? (array) json_decode((string) $placement['conditions'], true)
                    : null;
                if (!$evaluator->evaluate($conditions, $context)) {
                    continue;
                }

                $type   = (string) $placement['component_type'];
                $params = !empty($placement['params'])
                    ? (array) json_decode((string) $placement['params'], true)
                    : [];
                $html .= $renderer->render($type, $params, $context);
            }

            return $html;
        } catch (\Throwable $e) {
            if ((bool) config('app.debug', false)) {
                return '<!-- slot error [' . htmlspecialchars($slotName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ']: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->';
            }
            return '';
        }
    }

    /**
     * Assemble a RenderContext from any explicit override, falling back to the
     * request-scoped page context that FrontendController stashes on Flight
     * at the start of each request.
     *
     * @param array<string, mixed> $override
     */
    private function buildContext(array $override): RenderContext
    {
        $stash = [];
        try {
            $stash = (array) (\Flight::get('typedock.page_context') ?? []);
        } catch (\Throwable) {
            $stash = [];
        }

        $page = $override['page']
            ?? ($stash['page'] ?? null);
        $contextType = (string) (
            $override['context_type']
            ?? ($stash['context_type'] ?? '')
        );
        $term     = $override['term'] ?? ($stash['term'] ?? null);
        $postType = $override['post_type'] ?? ($stash['post_type'] ?? null);
        $routeType = $override['route_type'] ?? ($stash['route_type'] ?? null);

        return new RenderContext(
            locale: (string) config('app.locale', 'en'),
            page: is_array($page) ? $page : null,
            currentUrl: (string) ($_SERVER['REQUEST_URI'] ?? '/'),
            contextType: $contextType,
            term: is_array($term) ? $term : null,
            postType: is_string($postType) && $postType !== '' ? $postType : null,
            routeType: is_string($routeType) && $routeType !== '' ? $routeType : null,
        );
    }
}
