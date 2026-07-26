<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use TypeDock\Plugin\Form\FormCsrf;

// Plugin classes are autoloaded at runtime from plugin.json, not by Composer,
// so the unit test pulls the file in directly.
require_once TYPEDOCK_ROOT . '/plugins/form/src/FormCsrf.php';

class FormCsrfTest extends TestCase
{
    public function testVerificationIsOffUnlessTheOperatorAsksForIt(): void
    {
        $this->assertFalse(FormCsrf::required());
    }

    /**
     * The load-bearing property: deciding the policy must not open a session,
     * because a session cookie takes every page with a form on it out of the
     * CDN cache.
     */
    public function testDecidingThePolicyOpensNoSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('Another test left a session open.');
        }

        FormCsrf::required();

        $this->assertSame(PHP_SESSION_NONE, session_status());
    }
}
