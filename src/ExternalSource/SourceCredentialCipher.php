<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

final class SourceCredentialCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const INFO = 'typedock.source_credentials.v1';

    /**
     * @param array<string, mixed> $secrets
     */
    public function encrypt(array $secrets): string
    {
        $plain = json_encode($secrets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false || $tag === '') {
            throw new \RuntimeException('Failed to encrypt External Source credentials.');
        }

        return json_encode([
            'cipher' => self::CIPHER,
            'key_version' => 1,
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function decrypt(?string $payload): array
    {
        if ($payload === null || trim($payload) === '') {
            return [];
        }

        $data = json_decode($payload, true);
        if (!is_array($data)) {
            throw new \RuntimeException('External Source credential payload is invalid.');
        }

        $nonce = base64_decode((string) ($data['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($data['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($data['ciphertext'] ?? ''), true);

        if ($nonce === false || $tag === false || $ciphertext === false) {
            throw new \RuntimeException('External Source credential payload is corrupt.');
        }

        $plain = openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new \RuntimeException('External Source credentials could not be decrypted. Re-enter the credentials after APP_KEY rotation.');
        }

        $secrets = json_decode($plain, true);
        return is_array($secrets) ? $secrets : [];
    }

    private function key(): string
    {
        $appKey = (string) env('APP_KEY', '');
        if (!str_starts_with($appKey, 'base64:')) {
            throw new \RuntimeException('APP_KEY must be a base64: key before External Source credentials can be saved.');
        }

        $raw = base64_decode(substr($appKey, 7), true);
        if ($raw === false || strlen($raw) < 32) {
            throw new \RuntimeException('APP_KEY is invalid or too short for External Source credential encryption.');
        }

        return hash_hkdf('sha256', $raw, 32, self::INFO);
    }
}
