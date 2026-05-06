<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class Trust
{
    // Placeholder until the release signing key is generated. Preflight and
    // agent context are usable now; release verification should use this once
    // the real minisign public key is embedded.
    public const MINISIGN_PUBLIC_KEY = '';

    public static function signatureVerifier(): SignatureVerifier
    {
        if (self::MINISIGN_PUBLIC_KEY === '') {
            throw new \RuntimeException('TypeDock update signing key is not configured.');
        }
        return new SignatureVerifier(self::MINISIGN_PUBLIC_KEY);
    }
}
