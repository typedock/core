<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Component;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CoreComponentClassContractTest extends TestCase
{
    /**
     * @param list<string> $classes
     */
    #[DataProvider('componentClassContract')]
    public function testDocumentedCoreComponentClassesExistInDefaultTemplates(string $template, array $classes): void
    {
        $path = TYPEDOCK_ROOT . '/themes/default/components/' . $template;

        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach ($classes as $class) {
            self::assertStringContainsString($class, $source, "{$class} is documented but missing from {$template}");
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function componentClassContract(): iterable
    {
        yield 'search_form' => ['search-form.latte', [
            'search-form',
            'sr-only',
            'search-submit',
        ]];

        yield 'latest_posts' => ['latest-posts.latte', [
            'widget',
            'widget-latest-posts',
            'widget-title',
            'post-list',
            'post-list-item',
            'post-list-item-thumb',
            'post-list-item-body',
        ]];

        yield 'category_list' => ['category-list.latte', [
            'widget',
            'widget-category-list',
            'widget-title',
            'category-list',
            'count',
        ]];

        yield 'tag_cloud' => ['tag-cloud.latte', [
            'widget',
            'widget-tag-cloud',
            'widget-title',
            'tag-cloud',
            'tag-cloud-item',
        ]];

        yield 'related_posts' => ['related-posts.latte', [
            'related-posts',
            'related-posts-title',
            'widget-title',
            'related-posts-grid',
            'related-post-card',
            'related-post-thumb',
        ]];

        yield 'author_profile' => ['author-profile.latte', [
            'author-profile',
            'author-avatar',
            'author-info',
            'author-name',
            'author-bio',
            'author-links',
        ]];

        yield 'menu' => ['menu.latte', [
            'menu-list',
            'menu-item',
            'has-children',
            'sub-menu',
        ]];

        yield 'link_list' => ['link-list.latte', [
            'link-list',
            'link-list--',
            'link-list__item',
        ]];
    }
}
