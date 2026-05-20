<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\TestCase;
use TypeDock\Plugin\SourceGitHubDocs\GitHubDocsAdapter;

require_once TYPEDOCK_ROOT . '/plugins/source-github-docs/src/GitHubRequestException.php';
require_once TYPEDOCK_ROOT . '/plugins/source-github-docs/src/GitHubDocsAdapter.php';

final class GitHubDocsAdapterTest extends TestCase
{
    public function testSlugFromPathKeepsNestedDocsReadable(): void
    {
        $adapter = new GitHubDocsAdapter();
        $method = new \ReflectionMethod($adapter, 'slugFromPath');

        $this->assertSame('theme-json-reference', $method->invoke($adapter, 'theme-json-reference.md'));
        $this->assertSame('guides/install-guide', $method->invoke($adapter, 'Guides/Install Guide.md'));
    }

    public function testNormalizeDocumentExtractsMarkdownMetadata(): void
    {
        $adapter = new GitHubDocsAdapter();
        $method = new \ReflectionMethod($adapter, 'normalizeDocument');

        $item = $method->invoke($adapter, [
            'path' => 'docs/api.md',
            'sha' => 'abc123',
        ], <<<'MD'
---
title: API Reference
description: Stable endpoints for TypeDock integrations.
date: 2026-05-01
tags: [api, integrations]
---
# API Reference

Use the API with scoped bearer tokens.

## Authentication

Send an Authorization header.
MD, [
            'owner' => 'typedock',
            'repo' => 'core',
            'branch' => 'main',
            'docs_path' => 'docs',
        ]);

        $this->assertSame('abc123', $item['sys']['id']);
        $this->assertSame('api', $item['fields']['slug']);
        $this->assertSame('API Reference', $item['fields']['title']);
        $this->assertSame('Stable endpoints for TypeDock integrations.', $item['fields']['excerpt']);
        $this->assertSame('2026-05-01', $item['fields']['date']);
        $this->assertSame(['api', 'integrations'], $item['fields']['tags']);
        $this->assertStringStartsWith('Use the API', $item['fields']['content']);
        $this->assertSame('https://github.com/typedock/core/blob/main/docs/api.md', $item['fields']['html_url']);
    }
}
