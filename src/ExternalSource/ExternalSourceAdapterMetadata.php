<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

final class ExternalSourceAdapterMetadata
{
    /**
     * @param array<int, array<string, mixed>> $configFields
     * @param array<string, string> $defaultConfig
     * @param array<string, string> $defaultMapping
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $description,
        public readonly bool $tokenRequired,
        public readonly string $tokenLabel,
        public readonly string $tokenHelp,
        public readonly array $configFields,
        public readonly array $defaultConfig,
        public readonly array $defaultMapping,
        public readonly string $defaultDetailTemplate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'token_required' => $this->tokenRequired,
            'token_label' => $this->tokenLabel,
            'token_help' => $this->tokenHelp,
            'config_fields' => $this->configFields,
            'default_config' => $this->defaultConfig,
            'default_mapping' => $this->defaultMapping,
            'default_detail_template' => $this->defaultDetailTemplate,
        ];
    }
}
