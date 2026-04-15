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
                template: 'themes/default/components/search-form.latte',
                dataProvider: \TypeDock\Component\Provider\SearchFormProvider::class
            ),
            new ComponentDefinition(
                type: 'latest_posts',
                name: 'Latest Posts',
                params: [['name' => 'count', 'type' => 'number', 'default' => 5]],
                template: 'themes/default/components/latest-posts.latte',
                dataProvider: \TypeDock\Component\Provider\LatestPostsProvider::class
            ),
            new ComponentDefinition(
                type: 'related_posts',
                name: 'Related Posts',
                params: [['name' => 'count', 'type' => 'number', 'default' => 6]],
                template: 'themes/default/components/related-posts.latte',
                dataProvider: \TypeDock\Component\Provider\RelatedPostsProvider::class
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
                params: [['name' => 'limit', 'type' => 'number', 'default' => 30]],
                template: 'themes/default/components/tag-cloud.latte',
                dataProvider: \TypeDock\Component\Provider\TagCloudProvider::class
            ),
            new ComponentDefinition(
                type: 'menu',
                name: 'Menu',
                params: [['name' => 'location', 'type' => 'string', 'default' => 'primary']],
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
                dataProvider: null
            ),
            new ComponentDefinition(
                type: 'custom_html',
                name: 'Custom HTML',
                params: [['name' => 'html', 'type' => 'textarea', 'default' => '']],
                template: 'themes/default/components/custom-html.latte',
                dataProvider: null
            ),
        ];

        foreach ($components as $def) {
            $registry->register($def);
        }
    }
}
