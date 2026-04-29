<?php
declare(strict_types=1);

namespace TypeDock\Plugin\AdvancedBlocks;

use TypeDock\Component\ComponentDefinition;
use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

/**
 * Drop-in plugin that ships extra component blocks for the body editor.
 * The blocks are plain `componentBlock` types (no new Tiptap node), so the
 * existing slash menu, params modal, and TiptapRenderer handle them
 * without needing an editor rebuild.
 */
final class AdvancedBlocksPlugin implements PluginInterface
{
    public function register(PluginContext $ctx): void
    {
        $ctx->registerComponent(new ComponentDefinition(
            type: 'callout',
            name: 'Callout',
            description: 'Highlighted info / warning / success / danger box.',
            params: [
                [
                    'name'    => 'variant',
                    'label'   => 'Variant',
                    'type'    => 'select',
                    'default' => 'info',
                    'options' => [
                        'info'    => 'Info',
                        'success' => 'Success',
                        'warning' => 'Warning',
                        'danger'  => 'Danger',
                    ],
                ],
                [
                    'name'    => 'title',
                    'label'   => 'Title',
                    'type'    => 'text',
                    'default' => '',
                    'hint'    => 'Optional. Shown in bold above the body.',
                ],
                [
                    'name'    => 'body',
                    'label'   => 'Body',
                    'type'    => 'textarea',
                    'default' => '',
                ],
            ],
            placeable: ['block'],
            template: 'plugins/advanced-blocks/templates/components/callout.latte',
        ));

        $ctx->registerComponent(new ComponentDefinition(
            type: 'cta_button',
            name: 'CTA button',
            description: 'Prominent call-to-action button with optional caption.',
            params: [
                [
                    'name'    => 'label',
                    'label'   => 'Button label',
                    'type'    => 'text',
                    'default' => 'Learn more',
                ],
                [
                    'name'    => 'url',
                    'label'   => 'URL',
                    'type'    => 'text',
                    'default' => '',
                    'hint'    => 'Absolute or relative URL. Leave empty to render the button as disabled.',
                ],
                [
                    'name'    => 'variant',
                    'label'   => 'Style',
                    'type'    => 'select',
                    'default' => 'primary',
                    'options' => [
                        'primary'   => 'Primary (filled)',
                        'secondary' => 'Secondary (filled, muted)',
                        'outline'   => 'Outline',
                    ],
                ],
                [
                    'name'    => 'size',
                    'label'   => 'Size',
                    'type'    => 'select',
                    'default' => 'medium',
                    'options' => ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'],
                ],
                [
                    'name'    => 'align',
                    'label'   => 'Alignment',
                    'type'    => 'select',
                    'default' => 'center',
                    'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'],
                ],
                [
                    'name'    => 'new_tab',
                    'label'   => 'Open in new tab',
                    'type'    => 'boolean',
                    'default' => false,
                ],
                [
                    'name'    => 'caption',
                    'label'   => 'Caption (optional)',
                    'type'    => 'text',
                    'default' => '',
                    'hint'    => 'Small subtext shown below the button.',
                ],
            ],
            placeable: ['block'],
            template: 'plugins/advanced-blocks/templates/components/cta-button.latte',
        ));

        $ctx->registerComponent(new ComponentDefinition(
            type: 'balloon',
            name: 'Balloon (speech bubble)',
            description: 'Speaker icon with a speech bubble; left or right facing.',
            params: [
                [
                    'name'    => 'side',
                    'label'   => 'Side',
                    'type'    => 'select',
                    'default' => 'left',
                    'options' => ['left' => 'Left', 'right' => 'Right'],
                ],
                [
                    'name'    => 'speaker',
                    'label'   => 'Speaker name',
                    'type'    => 'text',
                    'default' => '',
                ],
                [
                    'name'    => 'avatar_url',
                    'label'   => 'Avatar image URL',
                    'type'    => 'text',
                    'default' => '',
                    'hint'    => 'Square image works best. Falls back to a placeholder when empty.',
                ],
                [
                    'name'    => 'body',
                    'label'   => 'Body',
                    'type'    => 'textarea',
                    'default' => '',
                ],
            ],
            placeable: ['block'],
            template: 'plugins/advanced-blocks/templates/components/balloon.latte',
        ));
    }

    public function getName(): string
    {
        return 'Advanced Blocks';
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
