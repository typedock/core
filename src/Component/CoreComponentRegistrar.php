<?php
declare(strict_types=1);

namespace TypeDock\Component;

class CoreComponentRegistrar
{
    public function register(ComponentRegistry $registry): void
    {
        $components = [
            new ComponentDefinition(
                type: 'search_form',
                name: 'Search Form',
                params: [['name' => 'placeholder', 'label' => 'Placeholder', 'type' => 'text', 'default' => 'Search...', 'hint' => 'Text shown inside the search field when empty.']],
                template: 'themes/default/components/search-form.latte',
                dataProvider: \TypeDock\Component\Provider\SearchFormProvider::class
            ),
            new ComponentDefinition(
                type: 'latest_posts',
                name: 'Latest Posts',
                params: [['name' => 'count', 'label' => 'Number of posts', 'type' => 'number', 'default' => 5, 'hint' => 'How many recent posts to show.']],
                template: 'themes/default/components/latest-posts.latte',
                dataProvider: \TypeDock\Component\Provider\LatestPostsProvider::class
            ),
            new ComponentDefinition(
                type: 'related_posts',
                name: 'Related Posts',
                params: [['name' => 'count', 'label' => 'Number of posts', 'type' => 'number', 'default' => 6, 'hint' => 'How many related posts to show.']],
                template: 'themes/default/components/related-posts.latte',
                dataProvider: \TypeDock\Component\Provider\RelatedPostsProvider::class,
                supportedContexts: ['post']
            ),
            new ComponentDefinition(
                type: 'category_list',
                name: 'Category List',
                template: 'themes/default/components/category-list.latte',
                dataProvider: \TypeDock\Component\Provider\CategoryListProvider::class
            ),
            new ComponentDefinition(
                type: 'tag_cloud',
                name: 'Tag Cloud',
                params: [['name' => 'limit', 'label' => 'Maximum tags', 'type' => 'number', 'default' => 30, 'hint' => 'Upper bound on how many tags to render.']],
                template: 'themes/default/components/tag-cloud.latte',
                dataProvider: \TypeDock\Component\Provider\TagCloudProvider::class
            ),
            new ComponentDefinition(
                type: 'menu',
                name: 'Menu',
                params: [['name' => 'location', 'label' => 'Menu location', 'type' => 'select_menu_location', 'default' => 'header', 'hint' => 'Which of the theme-declared menu locations to render.']],
                template: 'themes/default/components/menu.latte',
                dataProvider: \TypeDock\Component\Provider\MenuProvider::class
            ),
            new ComponentDefinition(
                type: 'archive_list',
                name: 'Monthly Archives',
                template: 'themes/default/components/archive-list.latte',
                dataProvider: \TypeDock\Component\Provider\ArchiveListProvider::class
            ),
            new ComponentDefinition(
                type: 'author_profile',
                name: 'Author Profile',
                template: 'themes/default/components/author-profile.latte',
                dataProvider: \TypeDock\Component\Provider\AuthorProfileProvider::class,
                supportedContexts: ['post', 'page']
            ),
            new ComponentDefinition(
                type: 'custom_html',
                name: 'Custom HTML',
                params: [['name' => 'html', 'label' => 'HTML', 'type' => 'textarea', 'default' => '', 'hint' => 'Rendered as-is. Do not paste untrusted markup.']],
                template: 'themes/default/components/custom-html.latte',
                dataProvider: null
            ),
            new ComponentDefinition(
                type: 'link_list',
                name: 'Link List',
                description: 'Renders a reusable list of links (footer legal pages, header utility links, etc.).',
                params: [
                    [
                        'name'   => 'links',
                        'label'  => 'Links',
                        'type'   => 'repeater',
                        'hint'   => 'Each row becomes one link.',
                        'default' => [],
                        'fields' => [
                            'label' => ['type' => 'text', 'label' => 'Label'],
                            'url'   => ['type' => 'text', 'label' => 'URL'],
                        ],
                    ],
                    [
                        'name'    => 'layout',
                        'label'   => 'Direction',
                        'type'    => 'select',
                        'default' => 'horizontal',
                        'options' => [
                            'horizontal' => 'Horizontal',
                            'vertical'   => 'Vertical',
                        ],
                    ],
                ],
                template: 'themes/default/components/link-list.latte',
                dataProvider: null
            ),
        ];

        foreach ($components as $def) {
            $registry->register($def);
        }
    }
}
