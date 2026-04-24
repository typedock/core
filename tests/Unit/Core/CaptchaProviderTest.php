<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use flight\Engine;
use PHPUnit\Framework\TestCase;
use TypeDock\Contract\CaptchaProvider;
use TypeDock\Contract\CaptchaResult;
use TypeDock\Core\ServiceProvider;
use TypeDock\Security\NullCaptchaProvider;

final class CaptchaProviderTest extends TestCase
{
    protected function setUp(): void
    {
        \Flight::setEngine(new Engine());
        (new ServiceProvider())->register();
    }

    public function testDefaultProviderIsNoOp(): void
    {
        $provider = \Flight::captcha();

        $this->assertInstanceOf(NullCaptchaProvider::class, $provider);
        $this->assertSame('', $provider->render('admin_login'));
        $this->assertTrue($provider->verify([], 'admin_login')->ok);
    }

    public function testPluginProviderOverridesDefault(): void
    {
        $fake = new class implements CaptchaProvider {
            public function render(string $action, array $context = []): string
            {
                return '<div data-test="captcha"></div>';
            }

            public function verify(array $payload, string $action, array $context = []): CaptchaResult
            {
                return CaptchaResult::fail('blocked');
            }
        };

        \Flight::provider_registry()->provide('captcha', $fake, 'captcha-test');

        $this->assertSame($fake, \Flight::captcha());
        $this->assertSame('<div data-test="captcha"></div>', \Flight::captcha()->render('form_submit'));
        $this->assertSame('blocked', \Flight::captcha()->verify([], 'form_submit')->error);
    }
}
