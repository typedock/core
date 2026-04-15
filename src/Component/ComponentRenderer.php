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

        // Resolve data via DataProvider
        $data = [];
        if ($def->dataProvider !== null && class_exists($def->dataProvider)) {
            /** @var DataProvider $provider */
            $provider = new $def->dataProvider();
            $data     = $provider->resolve($params, $context);
        }

        // Resolve template path
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
     * Resolve template path: theme override → component default
     */
    private function resolveTemplatePath(string $type, string $defaultTemplate): ?string
    {
        $activeTheme  = 'default'; // TODO: load from site_options
        $themeDir     = TYPEDOCK_ROOT . '/themes/' . $activeTheme;
        $templateName = str_replace('_', '-', $type) . '.latte';

        // Check theme override
        $themeOverride = $themeDir . '/components/' . $templateName;
        if (file_exists($themeOverride)) {
            return 'components/' . $templateName;
        }

        // Use defined template
        if ($defaultTemplate !== '' && file_exists(TYPEDOCK_ROOT . '/' . $defaultTemplate)) {
            return $defaultTemplate;
        }

        return null;
    }
}
