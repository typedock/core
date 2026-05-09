<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SimpleAiWriting;

use TypeDock\Contract\PluginInterface;
use TypeDock\Core\PluginContext;

final class SimpleAiWritingPlugin implements PluginInterface
{
    public function register(PluginContext $context): void
    {
        $controller = new SimpleAiWritingController($context);

        $context->registerEditorScript('editor-extension.js');
        $context->registerAdminRoute('GET', '', [$controller, 'edit']);
        $context->registerAdminRoute('POST', '', [$controller, 'update']);
        $context->registerAdminRoute('POST', 'rewrite-selection', [$controller, 'rewriteSelection']);
        $context->registerAdminRoute('POST', 'suggest-seo-fields', [$controller, 'suggestSeoFields']);
        $context->registerAdminRoute('POST', 'draft-article', [$controller, 'draftArticle']);
        $context->addAdminMenuItem('AI Writing', '');
    }

    public function getName(): string
    {
        return 'Simple AI Writing';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function provides(): array
    {
        return [];
    }
}
