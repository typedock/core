<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeDock\Http\UrlGuard;

/**
 * Only IP-literal hosts are exercised: a test that depends on DNS is a test
 * that fails on an aeroplane.
 */
final class UrlGuardTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback literal'      => ['http://127.0.0.1/x'],
            'loopback name'         => ['http://localhost/x'],
            'ipv6 loopback'         => ['http://[::1]/x'],
            'private class A'       => ['http://10.0.0.5/x'],
            'private class B'       => ['http://172.16.4.4/x'],
            'private class C'       => ['http://192.168.1.1/x'],
            'link local'            => ['http://169.254.1.1/x'],
            'cloud metadata'        => ['http://169.254.169.254/latest/meta-data/'],
            'mdns suffix'           => ['http://printer.local/x'],
            'internal suffix'       => ['http://db.internal/x'],
            'file scheme'           => ['file:///etc/passwd'],
            'gopher scheme'         => ['gopher://example.com/x'],
            'embedded credentials'  => ['http://user:pass@93.184.216.34/x'],
            'no host'               => ['http:///x'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testRefusesUrlsThatMustNotBeFetched(string $url): void
    {
        $this->assertNotNull(UrlGuard::reject($url), "Expected {$url} to be refused");
    }

    public function testAllowsAPublicAddressAndReportsTheIpToPin(): void
    {
        $inspected = UrlGuard::inspect('https://93.184.216.34/some/image.jpg');

        $this->assertSame('93.184.216.34', $inspected['ip']);
        $this->assertSame('https', $inspected['scheme']);
        $this->assertSame(443, $inspected['port'], 'Default port is filled in so callers can pin host:port:ip');
    }

    public function testExplicitPortIsPreserved(): void
    {
        $this->assertSame(8080, UrlGuard::inspect('http://93.184.216.34:8080/x')['port']);
    }

    public function testRejectReturnsTheReasonSoCallersCanShowIt(): void
    {
        $this->assertStringContainsString('private', (string) UrlGuard::reject('http://10.1.2.3/x'));
    }
}
