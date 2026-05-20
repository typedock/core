<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\TestCase;
use TypeDock\ExternalSource\ExternalSourceAdapterInterface;
use TypeDock\ExternalSource\ExternalSourceAdapterMetadata;
use TypeDock\ExternalSource\ExternalSourceAdapterRegistry;
use TypeDock\ExternalSource\ExternalSourceService;

final class ExternalSourceServiceConfigTest extends TestCase
{
    public function testNormalizeConfigReadsSelectedAdapterScopedFields(): void
    {
        $service = new ExternalSourceService(new \PDO('sqlite::memory:'), adapterRegistry: $this->registry());
        $method = new \ReflectionMethod($service, 'normalizeConfig');

        $config = $method->invoke($service, 'adapter_b', [
            'config' => [
                'adapter_a' => [
                    'owner' => 'wrong-owner',
                    'repo' => 'wrong-repo',
                ],
                'adapter_b' => [
                    'owner' => 'typedock',
                    'repo' => 'core',
                ],
            ],
        ], null);

        $this->assertSame('typedock', $config['owner']);
        $this->assertSame('core', $config['repo']);
    }

    public function testNormalizeConfigStillAcceptsLegacyFlatFields(): void
    {
        $service = new ExternalSourceService(new \PDO('sqlite::memory:'), adapterRegistry: $this->registry());
        $method = new \ReflectionMethod($service, 'normalizeConfig');

        $config = $method->invoke($service, 'adapter_b', [
            'owner' => 'typedock',
            'repo' => 'core',
        ], null);

        $this->assertSame('typedock', $config['owner']);
        $this->assertSame('core', $config['repo']);
    }

    private function registry(): ExternalSourceAdapterRegistry
    {
        $registry = new ExternalSourceAdapterRegistry();
        $registry->register($this->adapter('adapter_a'));
        $registry->register($this->adapter('adapter_b'));
        return $registry;
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
                    configFields: [
                        ['name' => 'owner', 'label' => 'Owner', 'type' => 'text', 'required' => true],
                        ['name' => 'repo', 'label' => 'Repository', 'type' => 'text', 'required' => true],
                    ],
                    defaultConfig: [
                        'owner' => '',
                        'repo' => '',
                    ],
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
