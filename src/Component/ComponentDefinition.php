<?php
declare(strict_types=1);

namespace TypeDock\Component;

class ComponentDefinition
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $icon = '',
        /** @var array<array<string, mixed>> */
        public readonly array $params = [],
        /** @var array<string> */
        public readonly array $placeable = ['slot', 'block'],
        public readonly string $template = '',
        public readonly ?string $dataProvider = null,
        public readonly ?string $module = null,
        /** @var array<string, mixed> */
        public readonly array $cache = [],
        /**
         * Context types this component can render in, matching the values
         * emitted by the frontend for each request ('post', 'page', 'archive',
         * 'search', etc.). An empty array means "works anywhere".
         *
         * @var array<string>
         */
        public readonly array $supportedContexts = [],
        /**
         * Whether this component was declared via theme.json (rather than
         * registered by CMS core / a plugin). Custom components resolve their
         * data through `fetch` and their template from the active theme's
         * components/ directory, not from a DataProvider class.
         */
        public readonly bool $isCustom = false,
        /**
         * Fetch definition from theme.json for custom components. Null for
         * standard components.
         *
         * @var array<string, array<string, mixed>>|null
         */
        public readonly ?array $fetch = null,
        /**
         * Absolute path to the template for custom components, already
         * resolved against the active theme's directory.
         */
        public readonly string $absoluteTemplatePath = ''
    ) {}
}
