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
        $this->seedNewsletterFormOnce($ctx);

        $ctx->registerComponent(new ComponentDefinition(
            type: 'form',
            name: 'Form',
            description: 'Renders a form defined in the Form plugin admin.',
            params: [[
                'name'  => 'form_id',
                'label' => 'Form',
                'type'  => 'select_form',
                'hint'  => 'Form created under /admin/plugins/form.',
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

    /**
     * Create a default `newsletter` form on the first request after the
     * plugin is enabled, so theme slot defaults like
     * `{"component":"form","params":{"slug":"newsletter"}}` render
     * something instead of going blank. The marker option keeps re-runs
     * cheap and lets operators delete the seed form without it coming
     * back on the next request.
     */
    private function seedNewsletterFormOnce(PluginContext $ctx): void
    {
        if ($ctx->getSiteOption('plugin.form.seeded_newsletter') !== null) {
            return;
        }
        $service = new FormService($ctx->db()->pdo());
        if ($service->findBySlug('newsletter') === null) {
            $service->create([
                'name'            => 'Newsletter',
                'slug'            => 'newsletter',
                'fields'          => [
                    [
                        'name'        => 'email',
                        'label'       => 'Email',
                        'type'        => 'email',
                        'required'    => true,
                        'placeholder' => 'you@example.com',
                        'options'     => [],
                    ],
                ],
                'success_message' => "Thanks! You're subscribed.",
            ]);
        }
        $ctx->setSiteOption('plugin.form.seeded_newsletter', 1, 'plugin.form');
    }
}
