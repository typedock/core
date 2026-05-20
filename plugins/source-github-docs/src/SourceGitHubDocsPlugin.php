<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SourceGitHubDocs;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class SourceGitHubDocsPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->registerExternalSourceAdapter(new GitHubDocsAdapter());
    }

    public function getName(): string
    {
        return 'GitHub Docs Source Adapter';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return ['external-source-adapter:github_docs'];
    }
}
