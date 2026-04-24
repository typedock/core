<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Social;

use TypeDock\Component\ComponentDefinition;
use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class SocialPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        SocialFollowProvider::usePdo($context->db()->pdo());

        $context->registerComponent(new ComponentDefinition(
            type: 'social_share',
            name: 'Social share buttons',
            description: 'Share the current page on major networks.',
            params: [
                [
                    'name'    => 'networks',
                    'label'   => 'Networks',
                    'type'    => 'text',
                    'default' => 'x,facebook,linkedin,hatena,line,email,copy',
                    'hint'    => 'Comma-separated list. Allowed: x, facebook, linkedin, bluesky, mastodon, hatena, line, email, copy.',
                ],
                [
                    'name'    => 'title',
                    'label'   => 'Shared title override',
                    'type'    => 'text',
                    'default' => '',
                    'hint'    => 'Falls back to the current page title.',
                ],
            ],
            placeable: ['slot', 'block'],
            template: 'plugins/social/templates/components/social-share.latte',
            dataProvider: SocialShareProvider::class,
        ));

        $context->registerComponent(new ComponentDefinition(
            type: 'social_follow',
            name: 'Social follow links',
            description: 'Follow links to the site\'s social accounts.',
            params: [
                ['name' => 'x_url',        'label' => 'X / Twitter URL',   'type' => 'text', 'default' => ''],
                ['name' => 'facebook_url', 'label' => 'Facebook URL',      'type' => 'text', 'default' => ''],
                ['name' => 'instagram_url','label' => 'Instagram URL',     'type' => 'text', 'default' => ''],
                ['name' => 'youtube_url',  'label' => 'YouTube URL',       'type' => 'text', 'default' => ''],
                ['name' => 'linkedin_url', 'label' => 'LinkedIn URL',      'type' => 'text', 'default' => ''],
                ['name' => 'github_url',   'label' => 'GitHub URL',        'type' => 'text', 'default' => ''],
                ['name' => 'rss_url',      'label' => 'RSS URL',           'type' => 'text', 'default' => ''],
            ],
            placeable: ['slot', 'block'],
            template: 'plugins/social/templates/components/social-follow.latte',
            dataProvider: SocialFollowProvider::class,
        ));
    }

    public function getName(): string
    {
        return 'Social';
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
