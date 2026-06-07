<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Locale;

use PHPUnit\Framework\TestCase;
use TypeDock\Locale\AdminLocaleResolver;
use TypeDock\Locale\Translator;

final class TranslatorTest extends TestCase
{
    public function testTranslatorFallsBackToOriginalForEnglish(): void
    {
        $translator = new Translator('en', TYPEDOCK_ROOT . '/resources/lang/admin');

        self::assertSame('Log in', $translator->translate('Log in'));
    }

    public function testTranslatorLoadsJapaneseAdminCatalog(): void
    {
        $translator = new Translator('ja', TYPEDOCK_ROOT . '/resources/lang/admin');

        self::assertSame('ログイン', $translator->translate('Log in'));
    }

    public function testTranslatorInterpolatesNamedParams(): void
    {
        $translator = new Translator('ja', TYPEDOCK_ROOT . '/resources/lang/admin');

        self::assertSame(
            'Postを保存しました。',
            $translator->translate('{noun} saved.', noun: 'Post'),
        );
    }

    public function testAdminLocaleResolverPrefersSupportedQueryLocale(): void
    {
        $oldGet = $_GET;
        $oldCookie = $_COOKIE;

        $_GET = ['lang' => 'ja'];
        $_COOKIE = ['typedock_admin_locale' => 'en'];

        try {
            $resolver = new AdminLocaleResolver(TYPEDOCK_ROOT . '/resources/lang/admin');
            self::assertSame('ja', $resolver->current());
        } finally {
            $_GET = $oldGet;
            $_COOKIE = $oldCookie;
        }
    }

    public function testAdminLocaleResolverFallsBackToCookie(): void
    {
        $oldGet = $_GET;
        $oldCookie = $_COOKIE;

        $_GET = [];
        $_COOKIE = ['typedock_admin_locale' => 'ja'];

        try {
            $resolver = new AdminLocaleResolver(TYPEDOCK_ROOT . '/resources/lang/admin');
            self::assertSame('ja', $resolver->current());
        } finally {
            $_GET = $oldGet;
            $_COOKIE = $oldCookie;
        }
    }

    public function testAdminLocaleResolverFallsBackToAcceptLanguage(): void
    {
        $oldGet = $_GET;
        $oldCookie = $_COOKIE;
        $oldServer = $_SERVER;

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-CA, ja-JP;q=0.9, en;q=0.8';

        try {
            $resolver = new AdminLocaleResolver(TYPEDOCK_ROOT . '/resources/lang/admin');
            self::assertSame('ja', $resolver->current());
        } finally {
            $_GET = $oldGet;
            $_COOKIE = $oldCookie;
            $_SERVER = $oldServer;
        }
    }

    public function testAdminLocaleResolverOnlyAcceptsSupportedSanitizedHeaderLocales(): void
    {
        $oldGet = $_GET;
        $oldCookie = $_COOKIE;
        $oldServer = $_SERVER;

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = '../../ja, zh-CN;q=0.9';

        try {
            $resolver = new AdminLocaleResolver(TYPEDOCK_ROOT . '/resources/lang/admin');
            self::assertSame('en', $resolver->current());
        } finally {
            $_GET = $oldGet;
            $_COOKIE = $oldCookie;
            $_SERVER = $oldServer;
        }
    }

    public function testAdminLocaleResolverDiscoversCatalogLocales(): void
    {
        $resolver = new AdminLocaleResolver(TYPEDOCK_ROOT . '/resources/lang/admin');

        self::assertSame('日本語', $resolver->locales()['ja'] ?? null);
        self::assertSame('English', $resolver->locales()['en'] ?? null);
    }
}
