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
                placeable: ['slot'],
                template: 'themes/default/components/search-form.latte',
                dataProvider: \TypeDock\Component\Provider\SearchFormProvider::class
            ),
            new ComponentDefinition(
                type: 'latest_posts',
                name: 'Latest Posts',
                params: [
                    ['name' => 'title', 'label' => 'Heading label', 'type' => 'text', 'default' => 'Latest Posts', 'hint' => 'Leave blank to render without a heading.'],
                    ['name' => 'count', 'label' => 'Number of posts', 'type' => 'number', 'default' => 5, 'hint' => 'How many recent posts to show.'],
                ],
                placeable: ['slot'],
                template: 'themes/default/components/latest-posts.latte',
                dataProvider: \TypeDock\Component\Provider\LatestPostsProvider::class
            ),
            new ComponentDefinition(
                type: 'related_posts',
                name: 'Related Posts',
                params: [
                    ['name' => 'title', 'label' => 'Heading label', 'type' => 'text', 'default' => 'Related Posts', 'hint' => 'Leave blank to render without a heading.'],
                    ['name' => 'count', 'label' => 'Number of posts', 'type' => 'number', 'default' => 6, 'hint' => 'How many related posts to show.'],
                ],
                placeable: ['slot'],
                template: 'themes/default/components/related-posts.latte',
                dataProvider: \TypeDock\Component\Provider\RelatedPostsProvider::class,
                supportedContexts: ['post']
            ),
            new ComponentDefinition(
                type: 'category_list',
                name: 'Category List',
                params: [
                    ['name' => 'title', 'label' => 'Heading label', 'type' => 'text', 'default' => 'Categories', 'hint' => 'Leave blank to render without a heading.'],
                ],
                placeable: ['slot'],
                template: 'themes/default/components/category-list.latte',
                dataProvider: \TypeDock\Component\Provider\CategoryListProvider::class
            ),
            new ComponentDefinition(
                type: 'tag_cloud',
                name: 'Tag Cloud',
                params: [
                    ['name' => 'title', 'label' => 'Heading label', 'type' => 'text', 'default' => 'Tags', 'hint' => 'Leave blank to render without a heading.'],
                    ['name' => 'limit', 'label' => 'Maximum tags', 'type' => 'number', 'default' => 30, 'hint' => 'Upper bound on how many tags to render.'],
                ],
                placeable: ['slot'],
                template: 'themes/default/components/tag-cloud.latte',
                dataProvider: \TypeDock\Component\Provider\TagCloudProvider::class
            ),
            new ComponentDefinition(
                type: 'menu',
                name: 'Menu',
                params: [['name' => 'location', 'label' => 'Menu location', 'type' => 'select_menu_location', 'default' => 'header', 'hint' => 'Which of the theme-declared menu locations to render.']],
                placeable: ['slot'],
                template: 'themes/default/components/menu.latte',
                dataProvider: \TypeDock\Component\Provider\MenuProvider::class
            ),
            new ComponentDefinition(
                type: 'archive_list',
                name: 'Monthly Archives',
                params: [
                    ['name' => 'title', 'label' => 'Heading label', 'type' => 'text', 'default' => 'Archive', 'hint' => 'Leave blank to render without a heading.'],
                ],
                placeable: ['slot'],
                template: 'themes/default/components/archive-list.latte',
                dataProvider: \TypeDock\Component\Provider\ArchiveListProvider::class
            ),
            new ComponentDefinition(
                type: 'author_profile',
                name: 'Author Profile',
                placeable: ['slot'],
                template: 'themes/default/components/author-profile.latte',
                dataProvider: \TypeDock\Component\Provider\AuthorProfileProvider::class,
                supportedContexts: ['post', 'page']
            ),
            new ComponentDefinition(
                type: 'toc',
                name: 'Table of Contents',
                description: 'Auto-generated list of headings (h2/h3) from the current page body.',
                params: [
                    [
                        'name'    => 'title',
                        'label'   => 'Heading label',
                        'type'    => 'text',
                        'default' => 'Table of Contents',
                        'hint'    => 'Leave blank to render without a heading.',
                    ],
                    [
                        'name'    => 'min_level',
                        'label'   => 'Minimum heading level',
                        'type'    => 'select',
                        'default' => '2',
                        'options' => ['2' => 'H2', '3' => 'H3'],
                    ],
                    [
                        'name'    => 'max_level',
                        'label'   => 'Maximum heading level',
                        'type'    => 'select',
                        'default' => '3',
                        'options' => ['2' => 'H2', '3' => 'H3', '4' => 'H4'],
                    ],
                ],
                placeable: ['slot', 'block'],
                template: 'themes/default/components/toc.latte',
                dataProvider: \TypeDock\Component\Provider\TocProvider::class,
                supportedContexts: ['post', 'page']
            ),
            new ComponentDefinition(
                type: 'custom_html',
                name: 'Custom HTML',
                params: [['name' => 'html', 'label' => 'HTML', 'type' => 'textarea', 'default' => '', 'hint' => 'Rendered as-is. Do not paste untrusted markup.']],
                template: 'themes/default/components/custom-html.latte',
                dataProvider: null,
                requiresCapability: 'content:unfiltered_html'
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
