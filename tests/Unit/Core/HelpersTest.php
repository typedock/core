<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (['TD_TEST_RAW', 'TD_TEST_BOOL_T', 'TD_TEST_BOOL_F', 'TD_TEST_NULL', 'TD_TEST_EMPTY', 'TD_TEST_MISSING'] as $k) {
            $this->savedEnv[$k] = getenv($k);
            putenv($k); // unset
            unset($_ENV[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            unset($_ENV[$k]);
            if ($v === false) {
                putenv($k);
            } else {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
    }

    public function testEnvReturnsRawString(): void
    {
        $_ENV['TD_TEST_RAW'] = 'hello';
        $this->assertSame('hello', env('TD_TEST_RAW'));
    }

    public function testEnvCoercesTextualBooleansAndNull(): void
    {
        $_ENV['TD_TEST_BOOL_T'] = 'true';
        $_ENV['TD_TEST_BOOL_F'] = 'false';
        $_ENV['TD_TEST_NULL']   = 'null';
        $_ENV['TD_TEST_EMPTY']  = 'empty';

        $this->assertTrue(env('TD_TEST_BOOL_T'));
        $this->assertFalse(env('TD_TEST_BOOL_F'));
        $this->assertNull(env('TD_TEST_NULL'));
        $this->assertSame('', env('TD_TEST_EMPTY'));
    }

    public function testEnvFallsBackToDefault(): void
    {
        $this->assertSame('fallback', env('TD_TEST_MISSING', 'fallback'));
        $this->assertNull(env('TD_TEST_MISSING'));
    }

    public function testBasePathAndStoragePathRespectRoot(): void
    {
        $this->assertSame(TYPEDOCK_ROOT, base_path());
        $this->assertSame(TYPEDOCK_ROOT . DIRECTORY_SEPARATOR . 'config', base_path('config'));
        $this->assertSame(TYPEDOCK_ROOT . '/storage' . DIRECTORY_SEPARATOR . 'cache', storage_path('cache'));
    }
}
