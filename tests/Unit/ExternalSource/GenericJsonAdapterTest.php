<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\ExternalSource;

use PHPUnit\Framework\TestCase;
use TypeDock\ExternalSource\GenericJsonAdapter;

final class GenericJsonAdapterTest extends TestCase
{
    public function testNormalizeItemUsesConfiguredSlugField(): void
    {
        $adapter = new GenericJsonAdapter();
        $method = new \ReflectionMethod($adapter, 'normalizeItem');

        $item = $method->invoke($adapter, [
            'id' => 123,
            'permalink' => 'hello-world',
            'title' => 'Hello',
        ], [
            'slug_field' => 'permalink',
        ]);

        $this->assertSame('hello-world', $item['fields']['slug']);
        $this->assertSame('123', $item['sys']['id']);
    }

    public function testConfigAllowsBasicAuthModeAndUsername(): void
    {
        $adapter = new GenericJsonAdapter();
        $method = new \ReflectionMethod($adapter, 'config');

        $config = $method->invoke($adapter, [
            'config' => [
                'json_list_url' => 'https://example.com/items',
                'json_auth_mode' => 'basic',
                'json_basic_username' => 'api-user',
            ],
        ]);

        $this->assertSame('basic', $config['auth_mode']);
        $this->assertSame('api-user', $config['basic_username']);
    }

    public function testBasicAuthRequiresUsernameAndToken(): void
    {
        $adapter = new GenericJsonAdapter();
        $method = new \ReflectionMethod($adapter, 'request');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Generic JSON Basic auth requires');
        $method->invoke($adapter, [
            'list_url' => 'https://example.com/items',
            'detail_url' => '',
            'items_path' => '',
            'total_path' => '',
            'auth_mode' => 'basic',
            'basic_username' => '',
            'slug_field' => 'slug',
        ], [], 'https://example.com/items');
    }

    public function testPublicUrlGuardBlocksLocalhost(): void
    {
        $adapter = new GenericJsonAdapter();
        $method = new \ReflectionMethod($adapter, 'assertPublicHttpUrl');

        $this->expectException(\RuntimeException::class);
        $method->invoke($adapter, 'http://localhost:8080/items');
    }

    public function testPublicUrlGuardBlocksPrivateIp(): void
    {
        $adapter = new GenericJsonAdapter();
        $method = new \ReflectionMethod($adapter, 'assertPublicHttpUrl');

        $this->expectException(\RuntimeException::class);
        $method->invoke($adapter, 'http://192.168.0.10/items');
    }
}
