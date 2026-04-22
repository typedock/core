<?php
declare(strict_types=1);

namespace TypeDock\Component;

use TypeDock\Theme\LatteFactory;

class ComponentRenderer
{
    public function __construct(
        private readonly ComponentRegistry $registry,
        private readonly LatteFactory $latte
    ) {}

    /**
     * Render a component to HTML.
     *
     * @param  array<string, mixed> $params
     */
    public function render(
        string $type,
        array $params = [],
        ?RenderContext $context = null
    ): string {
        $def = $this->registry->get($type);

        if ($def === null) {
            return '<!-- component not found: ' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->';
        }

        $context ??= new RenderContext(
            locale: config('app.locale', 'en'),
            currentUrl: $_SERVER['REQUEST_URI'] ?? '/'
        );

        // Refuse to render when the current request context isn't one this
        // component supports. Surfaced as an HTML comment so theme authors can
        // see *why* their sidebar widget silently disappeared on an archive
        // page (e.g. related_posts requires a 'post' context).
        if (!empty($def->supportedContexts)
            && $context->contextType !== ''
            && !in_array($context->contextType, $def->supportedContexts, true)
        ) {
            $supported = implode(', ', $def->supportedContexts);
            return '<!-- component "' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" skipped: requires context ' . htmlspecialchars($supported, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ', current is "' . htmlspecialchars($context->contextType, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" -->';
        }

        // Custom components: fetch-driven, template lives in the declaring theme.
        if ($def->isCustom) {
            $resolver     = new FetchResolver();
            $fetchObj     = $resolver->resolve($def->fetch, $params, $context);
            $templatePath = $def->absoluteTemplatePath;
            if ($templatePath === '' || !file_exists($templatePath)) {
                return '<!-- custom component template not found: ' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->';
            }

            return $this->latte->renderToString($templatePath, [
                'fetch'   => $fetchObj,
                'params'  => (object) $params,
                'context' => $context,
            ]);
        }

        // Standard components: data via DataProvider class.
        $data = [];
        if ($def->dataProvider !== null && class_exists($def->dataProvider)) {
            /** @var DataProvider $provider */
            $provider = new $def->dataProvider();
            $data     = $provider->resolve($params, $context);
        }

        $templatePath = $this->resolveTemplatePath($type, $def->template);
        if ($templatePath === null) {
            return '<!-- component template not found: ' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' -->';
        }

        return $this->latte->renderToString($templatePath, array_merge($data, [
            'params'  => $params,
            'context' => $context,
        ]));
    }

    /**
     * Resolve the template path for a component.
     *
     * Lookup order:
     *   1. The active theme's components/ override (so themes can restyle shared widgets).
     *   2. The default theme's components/ directory (shared fallback — lets lightweight
     *      themes like kinari ship without re-declaring every component template).
     *   3. The component's declared default template (usually points at the default theme too).
     *
     * Absolute paths are returned so LatteFactory::resolvePath doesn't prepend
     * the active theme directory and lose the fallback.
     */
    private function resolveTemplatePath(string $type, string $defaultTemplate): ?string
    {
        $activeTheme  = $this->latte->getActiveTheme();
        $templateName = str_replace('_', '-', $type) . '.latte';

        $themeOverride = TYPEDOCK_ROOT . '/themes/' . $activeTheme . '/components/' . $templateName;
        if (file_exists($themeOverride)) {
            return $themeOverride;
        }

        $defaultThemeFallback = TYPEDOCK_ROOT . '/themes/default/components/' . $templateName;
        if (file_exists($defaultThemeFallback)) {
            return $defaultThemeFallback;
        }

        if ($defaultTemplate !== '') {
            $absolute = TYPEDOCK_ROOT . '/' . $defaultTemplate;
            if (file_exists($absolute)) {
                return $absolute;
            }
        }

        return null;
    }
}
