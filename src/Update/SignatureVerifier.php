<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class SignatureVerifier
{
    public function __construct(
        private readonly string $trustedPublicKeyBase64,
    ) {}

    public function verifySha256(string $artifactPath, string $expectedSha256): void
    {
        if (!is_file($artifactPath)) {
            throw new \RuntimeException('Artifact not found: ' . $artifactPath);
        }

        $expected = strtolower(preg_replace('/^sha256:/', '', trim($expectedSha256)) ?? '');
        if ($expected === '' || !hash_equals($expected, hash_file('sha256', $artifactPath))) {
            throw new \RuntimeException('Package checksum verification failed.');
        }
    }

    public function verifyMinisign(string $artifactPath, string $minisigPath): void
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('PHP ext-sodium is required to verify package signatures.');
        }
        if (!is_file($artifactPath) || !is_file($minisigPath)) {
            throw new \RuntimeException('Package or signature file is missing.');
        }

        $trustedKey = base64_decode($this->trustedPublicKeyBase64, true);
        if (!is_string($trustedKey) || strlen($trustedKey) < 32) {
            throw new \RuntimeException('Trusted update public key is malformed.');
        }
        $publicKey = strlen($trustedKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ? $trustedKey
            : substr($trustedKey, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);

        $lines = preg_split('/\r?\n/', trim((string) file_get_contents($minisigPath)));
        if (!is_array($lines) || count($lines) < 4) {
            throw new \RuntimeException('Minisign signature is malformed.');
        }

        $signatureRecord = base64_decode(trim($lines[1]), true);
        if (!is_string($signatureRecord) || strlen($signatureRecord) < 74) {
            throw new \RuntimeException('Minisign signature record is malformed.');
        }

        $algorithm = substr($signatureRecord, 0, 2);
        $signature = substr($signatureRecord, 10, 64);
        $payload = (string) file_get_contents($artifactPath);
        $message = $algorithm === 'Ed'
            ? sodium_crypto_generichash($payload, '', 64)
            : $payload;

        if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new \RuntimeException('Package signature verification failed.');
        }

        $trustedComment = $lines[2];
        $globalRecord = base64_decode(trim($lines[3]), true);
        if (!is_string($globalRecord) || strlen($globalRecord) < 64) {
            throw new \RuntimeException('Minisign trusted-comment signature is malformed.');
        }
        $globalSignature = substr($globalRecord, -64);
        if (!sodium_crypto_sign_verify_detached($globalSignature, $signature . $trustedComment, $publicKey)) {
            throw new \RuntimeException('Minisign trusted-comment verification failed.');
        }
    }
}
