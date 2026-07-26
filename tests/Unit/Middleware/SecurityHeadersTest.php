<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeDock\Security\AdminCspPolicy;

final class SecurityHeadersTest extends TestCase
{
    public function testBasePolicyDoesNotTrustThirdPartyOrigins(): void
    {
        $policy = (new AdminCspPolicy())->toHeaderValue();

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringContainsString("frame-src 'self'", $policy);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $policy);
    }

    public function testPluginSourcesAreAddedToDeclaredDirectivesOnly(): void
    {
        $policy = new AdminCspPolicy();
        $policy->addPluginSources([
            'script-src' => ['https://challenges.cloudflare.com'],
            'frame-src' => ['https://challenges.cloudflare.com'],
        ]);

        $header = $policy->toHeaderValue();
        $this->assertStringContainsString(
            "script-src 'self' 'unsafe-inline' https://challenges.cloudflare.com",
            $header
        );
        $this->assertStringContainsString(
            "frame-src 'self' https://challenges.cloudflare.com",
            $header
        );
        $this->assertStringContainsString("connect-src 'self';", $header);
    }

    /** @param array<string, mixed> $declaration */
    #[DataProvider('invalidDeclarationProvider')]
    public function testUnsafePluginDeclarationsAreRejected(array $declaration): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdminCspPolicy::validatePluginSources($declaration);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidDeclarationProvider(): array
    {
        return [
            'protected directive' => [['object-src' => ['https://cdn.example.com']]],
            'wildcard' => [['script-src' => ['*']]],
            'unsafe eval' => [['script-src' => ["'unsafe-eval'"]]],
            'plain HTTP' => [['script-src' => ['http://cdn.example.com']]],
            'path' => [['script-src' => ['https://cdn.example.com/sdk.js']]],
            'credentials' => [['script-src' => ['https://user@example.com']]],
            'header injection' => [['frame-src' => ["https://example.com;\nobject-src *"]]],
            'WSS outside connect' => [['script-src' => ['wss://socket.example.com']]],
        ];
    }

    public function testConnectSourceMayUseSecureWebSocketOrigin(): void
    {
        $policy = new AdminCspPolicy();
        $policy->addPluginSources(['connect-src' => ['wss://socket.example.com']]);

        $this->assertStringContainsString(
            "connect-src 'self' wss://socket.example.com",
            $policy->toHeaderValue()
        );
    }

    public function testTurnstileManifestDeclaresItsRequiredAdminSources(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(TYPEDOCK_ROOT . '/plugins/turnstile-captcha/plugin.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $sources = AdminCspPolicy::validatePluginSources($manifest['admin_csp'] ?? []);
        $this->assertSame(
            ['https://challenges.cloudflare.com'],
            $sources['script-src']
        );
        $this->assertSame(
            ['https://challenges.cloudflare.com'],
            $sources['frame-src']
        );
    }
}
