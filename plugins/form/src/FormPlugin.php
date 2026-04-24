<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Form;

use TypeDock\Component\ComponentDefinition;
use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

/**
 * Form plugin — lives under plugins/form/ as a drop-in plugin (not in src/),
 * demonstrating that a plugin can be self-contained with its own manifest,
 * source tree, migrations, and templates. Zero BaseAdminController coupling.
 */
class FormPlugin implements PluginInterface
{
    public function register(PluginContext $ctx): void
    {
        $ctx->migrate(__DIR__ . '/../migrations');

        $ctx->registerComponent(new ComponentDefinition(
            type: 'form',
            name: 'Form',
            description: 'Renders a form defined in the Form plugin admin.',
            params: [[
                'name'  => 'form_id',
                'label' => 'Form ID',
                'type'  => 'text',
                'hint'  => 'UUID of the form created under /admin/plugins/form.',
            ]],
            placeable: ['slot', 'block'],
            template: 'themes/default/components/form.latte',
            dataProvider: FormDataProvider::class,
        ));

        $ctx->registerRoute('POST', 'submit', [new FormSubmitController($ctx), 'submit']);

        $controller = new FormAdminController($ctx);
        $ctx->registerAdminRoute('GET',  '',                [$controller, 'index']);
        $ctx->registerAdminRoute('GET',  'new',             [$controller, 'create']);
        $ctx->registerAdminRoute('POST', '',                [$controller, 'store']);
        $ctx->registerAdminRoute('GET',  '@id/edit',        fn(string $id) => $controller->edit($id));
        $ctx->registerAdminRoute('POST', '@id',             fn(string $id) => $controller->update($id));
        $ctx->registerAdminRoute('POST', '@id/delete',      fn(string $id) => $controller->destroy($id));
        $ctx->registerAdminRoute('GET',  '@id/submissions', fn(string $id) => $controller->submissions($id));

        $ctx->addAdminMenuItem('Forms', '');
    }

    public function getName(): string
    {
        return 'Form';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return [];
    }
}
