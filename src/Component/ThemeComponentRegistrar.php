<?php
declare(strict_types=1);

namespace TypeDock\Component;

/**
 * Register custom components declared by a theme's `components.custom` block
 * in theme.json. Called after core + plugin components so theme declarations
 * can override — rarely needed, but lets a theme shadow a built-in with the
 * same type key if it truly wants to.
 */
class ThemeComponentRegistrar
{
    public function __construct(
        private readonly string $themesDir,
    ) {}

    public function registerForTheme(string $themeName, ComponentRegistry $registry): void
    {
        $themeRoot = $this->themesDir . '/' . $themeName;
        $configPath = $themeRoot . '/theme.json';
        if (!is_file($configPath)) {
            return;
        }

        $config = json_decode((string) file_get_contents($configPath), true);
        if (!is_array($config)) {
            return;
        }

        $customs = $config['components']['custom'] ?? [];
        if (!is_array($customs) || $customs === []) {
            return;
        }

        foreach ($customs as $type => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $template = (string) ($spec['template'] ?? '');
            if ($template === '') {
                continue;
            }

            $absolute = $themeRoot . '/' . ltrim($template, '/');

            $registry->register(new ComponentDefinition(
                type: (string) $type,
                name: (string) ($spec['name'] ?? $type),
                description: (string) ($spec['description'] ?? ''),
                icon: '',
                params: $this->normalizeParams($spec['params'] ?? []),
                placeable: ['slot', 'block'],
                template: $template,
                dataProvider: null,
                module: null,
                cache: [],
                supportedContexts: isset($spec['context']) && is_array($spec['context'])
                    ? array_map('strval', $spec['context'])
                    : [],
                isCustom: true,
                fetch: isset($spec['fetch']) && is_array($spec['fetch']) ? $spec['fetch'] : null,
                absoluteTemplatePath: $absolute,
            ));
        }
    }

    /**
     * The admin params UI expects a list of rows with a `name` key; theme.json
     * declares params as a map keyed by name. Normalise to the list form.
     *
     * @param  array<string, mixed>|array<int, mixed> $params
     * @return array<array<string, mixed>>
     */
    private function normalizeParams(mixed $params): array
    {
        if (!is_array($params) || $params === []) {
            return [];
        }
        // Already in list form.
        if (array_is_list($params)) {
            return $params;
        }
        $out = [];
        foreach ($params as $name => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $out[] = array_merge(['name' => (string) $name], $spec);
        }
        return $out;
    }
}
