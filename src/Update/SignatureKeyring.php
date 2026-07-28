<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class SignatureKeyring
{
    /**
     * @param list<string> $publicKeys
     */
    public function __construct(private readonly array $publicKeys) {}

    /**
     * Verify against every trusted release key and return the matching key ID.
     */
    public function verifyMinisign(string $artifactPath, string $minisigPath): string
    {
        if ($this->publicKeys === []) {
            throw new \RuntimeException('TypeDock update signing keys are not configured.');
        }
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('PHP ext-sodium is required to verify package signatures.');
        }
        if (!is_file($artifactPath) || !is_file($minisigPath)) {
            throw new \RuntimeException('Package or signature file is missing.');
        }

        foreach ($this->publicKeys as $publicKey) {
            try {
                $verifier = new SignatureVerifier($publicKey);
                $verifier->verifyMinisign($artifactPath, $minisigPath);
                return $verifier->keyId();
            } catch (\RuntimeException) {
                // A release needs one valid signature from the pinned keyring.
            }
        }

        throw new \RuntimeException('Package signature was not made by a trusted TypeDock release key.');
    }
}
