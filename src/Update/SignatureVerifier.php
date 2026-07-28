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

        $trustedKey = $this->publicKeyRecord();
        $publicKey = substr($trustedKey, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $trustedKeyId = strlen($trustedKey) === 42 ? substr($trustedKey, 2, 8) : null;

        $lines = preg_split('/\r?\n/', trim((string) file_get_contents($minisigPath)));
        if (!is_array($lines) || count($lines) < 4) {
            throw new \RuntimeException('Minisign signature is malformed.');
        }

        $signatureRecord = base64_decode(trim($lines[1]), true);
        if (!is_string($signatureRecord) || strlen($signatureRecord) < 74) {
            throw new \RuntimeException('Minisign signature record is malformed.');
        }

        $algorithm = substr($signatureRecord, 0, 2);
        if (!in_array($algorithm, ['Ed', 'ED'], true)) {
            throw new \RuntimeException('Minisign signature uses an unsupported algorithm.');
        }
        if ($trustedKeyId !== null && !hash_equals($trustedKeyId, substr($signatureRecord, 2, 8))) {
            throw new \RuntimeException('Minisign signature was made by a different key.');
        }
        $signature = substr($signatureRecord, 10, 64);
        $payload = (string) file_get_contents($artifactPath);
        // Minisign's current default is the pre-hashed "ED" variant. The
        // legacy direct-message form is identified by "Ed".
        $message = $algorithm === 'ED'
            ? sodium_crypto_generichash($payload, '', 64)
            : $payload;

        if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
            throw new \RuntimeException('Package signature verification failed.');
        }

        $trustedCommentLine = trim($lines[2]);
        $prefix = 'trusted comment: ';
        if (!str_starts_with($trustedCommentLine, $prefix)) {
            throw new \RuntimeException('Minisign trusted comment is malformed.');
        }
        $trustedComment = substr($trustedCommentLine, strlen($prefix));
        if ($trustedComment === '') {
            throw new \RuntimeException('Minisign trusted comment is empty.');
        }
        $globalRecord = base64_decode(trim($lines[3]), true);
        if (!is_string($globalRecord) || strlen($globalRecord) < 64) {
            throw new \RuntimeException('Minisign trusted-comment signature is malformed.');
        }
        $globalSignature = substr($globalRecord, -64);
        if (!sodium_crypto_sign_verify_detached($globalSignature, $signature . $trustedComment, $publicKey)) {
            throw new \RuntimeException('Minisign trusted-comment verification failed.');
        }
    }

    public function keyId(): string
    {
        $trustedKey = $this->publicKeyRecord();
        if (strlen($trustedKey) === 42) {
            return strtoupper(bin2hex(substr($trustedKey, 2, 8)));
        }

        return strtoupper(substr(hash('sha256', $trustedKey), 0, 16));
    }

    private function publicKeyRecord(): string
    {
        $keyText = trim($this->trustedPublicKeyBase64);
        if (str_contains($keyText, "\n")) {
            $keyLines = preg_split('/\r?\n/', $keyText);
            $keyText = trim((string) end($keyLines));
        }
        $trustedKey = base64_decode($keyText, true);
        if (!is_string($trustedKey) || !in_array(strlen($trustedKey), [32, 42], true)) {
            throw new \RuntimeException('Trusted update public key is malformed.');
        }

        return $trustedKey;
    }
}
