<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TypeDock\Update\SignatureKeyring;
use TypeDock\Update\SignatureVerifier;

final class SignatureVerifierTest extends TestCase
{
    private string $dir;
    private string $artifact;
    private string $signature;
    private string $publicKey;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typedock-signature-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
        $this->artifact = $this->dir . '/package.zip';
        $this->signature = $this->artifact . '.minisig';
        file_put_contents($this->artifact, 'signed TypeDock package');

        $keyPair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $public = sodium_crypto_sign_publickey($keyPair);
        $keyId = random_bytes(8);
        $this->publicKey = base64_encode('Ed' . $keyId . $public);

        $payload = (string) file_get_contents($this->artifact);
        $detached = sodium_crypto_sign_detached(
            sodium_crypto_generichash($payload, '', 64),
            $secret,
        );
        $record = 'ED' . $keyId . $detached;
        $comment = 'timestamp:1785110400 file:package.zip';
        $global = sodium_crypto_sign_detached($detached . $comment, $secret);
        file_put_contents(
            $this->signature,
            "untrusted comment: signature from minisign secret key\n"
            . base64_encode($record) . "\n"
            . "trusted comment: {$comment}\n"
            . base64_encode($global) . "\n",
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->signature);
        @unlink($this->artifact);
        @rmdir($this->dir);
    }

    public function testVerifiesCurrentPrehashedMinisignFormat(): void
    {
        $verifier = new SignatureVerifier($this->publicKey);
        $verifier->verifyMinisign($this->artifact, $this->signature);
        $decoded = base64_decode($this->publicKey, true);
        self::assertIsString($decoded);
        self::assertSame(strtoupper(bin2hex(substr($decoded, 2, 8))), $verifier->keyId());
    }

    public function testKeyringAcceptsSignatureFromRecoveryKey(): void
    {
        $otherPair = sodium_crypto_sign_keypair();
        $otherKey = base64_encode(
            'Ed'
            . random_bytes(8)
            . sodium_crypto_sign_publickey($otherPair),
        );

        $keyId = (new SignatureKeyring([$otherKey, $this->publicKey]))
            ->verifyMinisign($this->artifact, $this->signature);

        $decoded = base64_decode($this->publicKey, true);
        self::assertIsString($decoded);
        self::assertSame(strtoupper(bin2hex(substr($decoded, 2, 8))), $keyId);
    }

    public function testKeyringRejectsSignatureOutsideTrustedKeys(): void
    {
        $otherPair = sodium_crypto_sign_keypair();
        $otherKey = base64_encode(
            'Ed'
            . random_bytes(8)
            . sodium_crypto_sign_publickey($otherPair),
        );

        $this->expectExceptionMessage('trusted TypeDock release key');
        (new SignatureKeyring([$otherKey]))->verifyMinisign($this->artifact, $this->signature);
    }

    public function testRejectsTamperedArtifact(): void
    {
        file_put_contents($this->artifact, 'tampered');
        $this->expectExceptionMessage('signature verification failed');
        (new SignatureVerifier($this->publicKey))->verifyMinisign($this->artifact, $this->signature);
    }

    public function testRejectsDifferentKeyId(): void
    {
        $decoded = base64_decode($this->publicKey, true);
        self::assertIsString($decoded);
        $other = base64_encode(substr($decoded, 0, 2) . random_bytes(8) . substr($decoded, 10));

        $this->expectExceptionMessage('different key');
        (new SignatureVerifier($other))->verifyMinisign($this->artifact, $this->signature);
    }
}
