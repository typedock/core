<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\TestCase;
use TypeDock\ExternalSource\GitHubIssuesAdapter;

final class GitHubIssuesAdapterTest extends TestCase
{
    public function testNormalizeIssueAddsPublicSlug(): void
    {
        $adapter = new GitHubIssuesAdapter();
        $method = new \ReflectionMethod($adapter, 'normalizeIssue');

        $item = $method->invoke($adapter, [
            'id' => 12345,
            'number' => 691,
            'updated_at' => '2026-04-14T00:00:00Z',
        ]);

        $this->assertSame('issue-691', $item['fields']['slug']);
    }
}
