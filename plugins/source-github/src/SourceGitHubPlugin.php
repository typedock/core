<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SourceGitHub;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class SourceGitHubPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $context->registerExternalSourceAdapter(new GitHubIssuesAdapter());
    }

    public function getName(): string
    {
        return 'GitHub Issues Source Adapter';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function provides(): array
    {
        return ['external-source-adapter:github_issues'];
    }
}
