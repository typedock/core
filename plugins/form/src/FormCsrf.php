<?php
declare(strict_types=1);

namespace TypeDock\Plugin\Form;

/**
 * Whether a form submission needs CSRF verification. Off unless the operator
 * asks for it.
 *
 * A CSRF token protects a victim's *ambient authority* — the attacker makes
 * the victim's browser spend privileges it already holds. Submitting a
 * TypeDock form spends nothing: the endpoint stores a row and sends a
 * notification mail, identically for an anonymous visitor and a signed-in
 * editor. An attacker can POST to it directly, so the token stops no spam
 * and no abuse, while forcing every page containing a form to carry a
 * per-visitor session cookie — which is a CDN's cue to skip the page.
 *
 * Abuse protection comes from FormAntispam (honeypot + per-IP rate limit)
 * and the captcha provider instead. Contact Form 7 makes the same trade with
 * its `WPCF7_VERIFY_NONCE` default.
 *
 * Strict mode exists for operators who want the token regardless; it costs
 * them the cacheability of every page with a form on it.
 */
final class FormCsrf
{
    public const OPTION = 'plugin.form.verify_csrf';

    public static function required(): bool
    {
        return (bool) site_option(self::OPTION, false);
    }
}
