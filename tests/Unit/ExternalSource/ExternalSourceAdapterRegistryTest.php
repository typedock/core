<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\TestCase;
use TypeDock\ExternalSource\ExternalSourceAdapterInterface;
use TypeDock\ExternalSource\ExternalSourceAdapterMetadata;
use TypeDock\ExternalSource\ExternalSourceAdapterRegistry;

final class ExternalSourceAdapterRegistryTest extends TestCase
{
    public function testBuiltInRegistryContainsDefaultAdapters(): void
    {
        $registry = ExternalSourceAdapterRegistry::withBuiltIns();

        $this->assertTrue($registry->has('wordpress_rest'));
        $this->assertTrue($registry->has('generic_json'));
        $this->assertFalse($registry->has('contentful'));
        $this->assertFalse($registry->has('github_issues'));
    }

    public function testRegisterRejectsDuplicateAdapterIds(): void
    {
        $registry = new ExternalSourceAdapterRegistry();
        $registry->register($this->adapter('demo'));

        $this->expectException(\InvalidArgumentException::class);
        $registry->register($this->adapter('demo'));
    }

    public function testRequireThrowsForUnknownAdapter(): void
    {
        $registry = new ExternalSourceAdapterRegistry();

        $this->expectException(\RuntimeException::class);
        $registry->require('missing');
    }

    private function adapter(string $id): ExternalSourceAdapterInterface
    {
        return new class($id) implements ExternalSourceAdapterInterface {
            public function __construct(private readonly string $id) {}

            public function metadata(): ExternalSourceAdapterMetadata
            {
                return new ExternalSourceAdapterMetadata(
                    id: $this->id,
                    label: 'Demo',
                    description: 'Demo adapter',
                    tokenRequired: false,
                    tokenLabel: 'Token',
                    tokenHelp: '',
                    configFields: [],
                    defaultConfig: [],
                    defaultMapping: [],
                    defaultDetailTemplate: '',
                );
            }

            public function list(array $source, array $credentials, int $limit, int $offset = 0): array
            {
                return ['items' => [], 'total' => 0];
            }

            public function getBySlug(array $source, array $credentials, string $slug): ?array
            {
                return null;
            }

            public function discoverFields(array $source, array $credentials): array
            {
                return [];
            }
        };
    }
}
