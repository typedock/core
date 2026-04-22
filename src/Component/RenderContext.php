<?php
declare(strict_types=1);

namespace TypeDock\Component;

class RenderContext
{
    public function __construct(
        public readonly string $locale = 'en',
        /** @var array<string, mixed>|null */
        public readonly ?array $page = null,
        public readonly string $currentUrl = '/',
        /**
         * Broad classification of the surrounding request so components can
         * declare which contexts they support (e.g. related_posts only makes
         * sense on a 'post' context). Empty string means "unknown" — the
         * component will be rendered without a context check.
         */
        public readonly string $contextType = '',
        /** @var array<string, mixed>|null */
        public readonly ?array $term = null,
        /**
         * Value of pages.page_type for the current page, if any
         * ('post' | 'page' | null).
         */
        public readonly ?string $pageType = null,
        /**
         * Which route family is rendering: 'single' | 'archive' | 'search' |
         * 'home' | null. Useful for fetch interpolation ({{context.route_type}}).
         */
        public readonly ?string $routeType = null,
    ) {}
}
