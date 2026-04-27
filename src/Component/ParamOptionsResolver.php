<?php
declare(strict_types=1);

namespace TypeDock\Component;

/**
 * Expand dynamic `select_*` parameter types into a plain `select` with an
 * `options` map, sourced from the live database (categories, tags, pages)
 * or the active theme's theme.json (menu locations). Themes declare these
 * in `components.custom.{name}.params` and the admin UI renders the result
 * with the same template code as a static select.
 */
class ParamOptionsResolver
{
    /**
     * Return a copy of the component definition with all dynamic select
     * types expanded. Called from admin controllers before handing the
     * definition to the slot / block params editor.
     */
    public function enrich(ComponentDefinition $def): ComponentDefinition
    {
        if (empty($def->params)) {
            return $def;
        }

        $changed = false;
        $params  = $def->params;
        foreach ($params as $i => $spec) {
            $type = (string) ($spec['type'] ?? '');
            $options = $this->optionsFor($type);
            if ($options === null) {
                continue;
            }
            $params[$i]['type']    = 'select';
            $params[$i]['options'] = $options;
            $changed = true;
        }

        if (!$changed) {
            return $def;
        }

        return new ComponentDefinition(
            type: $def->type,
            name: $def->name,
            description: $def->description,
            icon: $def->icon,
            params: $params,
            placeable: $def->placeable,
            template: $def->template,
            dataProvider: $def->dataProvider,
            module: $def->module,
            cache: $def->cache,
            supportedContexts: $def->supportedContexts,
            isCustom: $def->isCustom,
            fetch: $def->fetch,
            absoluteTemplatePath: $def->absoluteTemplatePath,
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function optionsFor(string $type): ?array
    {
        return match ($type) {
            'select_category'       => $this->categories(),
            'select_tag'            => $this->tags(),
            'select_page'           => $this->pages(),
            'select_form'           => $this->forms(),
            'select_menu_location'  => $this->menuLocations(),
            'select_collection'     => $this->collections(),
            default                 => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private function categories(): array
    {
        try {
            $stmt = \Flight::db()->query('SELECT slug, name FROM categories ORDER BY name ASC');
            $out  = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['slug']] = (string) $row['name'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function tags(): array
    {
        try {
            $stmt = \Flight::db()->query('SELECT slug, name FROM tags ORDER BY name ASC');
            $out  = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['slug']] = (string) $row['name'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function pages(): array
    {
        try {
            $stmt = \Flight::db()->query(
                "SELECT id, title FROM pages WHERE status = 'published' ORDER BY title ASC LIMIT 500"
            );
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['id']] = (string) $row['title'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function forms(): array
    {
        try {
            $stmt = \Flight::db()->query('SELECT id, name FROM plugin_form_forms ORDER BY name ASC');
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['id']] = (string) $row['name'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Read `menus` from the active theme's theme.json. Menus are declared
     * per-theme and auto-provisioned on first access, so the live DB is
     * not the source of truth — theme.json is.
     *
     * @return array<string, string>
     */
    private function menuLocations(): array
    {
        try {
            $loader = new \TypeDock\Theme\ThemeLoader();
            $active = $loader->resolveActiveTheme(\Flight::db());
            $config = $loader->loadThemeConfig($active);
            $menus  = $config['menus'] ?? [];
            $out    = [];
            foreach ($menus as $key => $def) {
                $label = is_array($def) ? (string) ($def['label'] ?? $key) : (string) $key;
                $out[(string) $key] = $label;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function collections(): array
    {
        try {
            // The Collection module's table may not exist on installs where
            // the module is disabled — fall back to an empty list if so.
            $stmt = \Flight::db()->query('SELECT handle, name FROM collections ORDER BY name ASC');
            $out  = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(string) $row['handle']] = (string) $row['name'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
