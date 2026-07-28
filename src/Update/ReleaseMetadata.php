<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class ReleaseMetadata
{
    /**
     * @param list<string> $revokedVersions
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $channel,
        public readonly string $version,
        public readonly string $releasedAt,
        public readonly string $minPhp,
        public readonly string $minCoreFrom,
        public readonly string $maxCoreFrom,
        public readonly string $zipUrl,
        public readonly string $signatureUrl,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        public readonly string $releaseNotesUrl,
        public readonly array $revokedVersions,
        public readonly bool $breakingChanges,
        public readonly bool $security,
    ) {}

    public static function fromJson(string $json, string $expectedChannel): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Update metadata is not valid JSON.');
        }

        $required = ['schema_version', 'channel', 'version', 'zip_url', 'signature_url', 'sha256', 'size_bytes'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new \RuntimeException("Update metadata is missing {$key}.");
            }
        }

        $channel = strtolower(trim((string) $data['channel']));
        if ($channel !== $expectedChannel) {
            throw new \RuntimeException("Update channel mismatch: expected {$expectedChannel}, got {$channel}.");
        }

        $version = ltrim(trim((string) $data['version']), 'v');
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $version) !== 1) {
            throw new \RuntimeException('Update metadata contains an invalid version.');
        }

        $sha256 = strtolower(trim((string) $data['sha256']));
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw new \RuntimeException('Update metadata contains an invalid SHA-256 digest.');
        }

        $size = (int) $data['size_bytes'];
        if ($size < 1 || $size > 134_217_728) {
            throw new \RuntimeException('Update package size is outside the supported range.');
        }

        $zipUrl = self::httpsUrl((string) $data['zip_url'], 'zip_url');
        $signatureUrl = self::httpsUrl((string) $data['signature_url'], 'signature_url');
        $notesUrl = trim((string) ($data['release_notes_url'] ?? ''));
        if ($notesUrl !== '') {
            $notesUrl = self::httpsUrl($notesUrl, 'release_notes_url');
        }

        $revoked = [];
        foreach ((array) ($data['revoked_versions'] ?? []) as $revokedVersion) {
            if (is_string($revokedVersion) && $revokedVersion !== '') {
                $revoked[] = ltrim(trim($revokedVersion), 'v');
            }
        }

        return new self(
            schemaVersion: (int) $data['schema_version'],
            channel: $channel,
            version: $version,
            releasedAt: (string) ($data['released_at'] ?? ''),
            minPhp: (string) ($data['min_php'] ?? '8.2.0'),
            minCoreFrom: (string) ($data['min_core_from'] ?? '0.0.0'),
            maxCoreFrom: (string) ($data['max_core_from'] ?? ''),
            zipUrl: $zipUrl,
            signatureUrl: $signatureUrl,
            sha256: $sha256,
            sizeBytes: $size,
            releaseNotesUrl: $notesUrl,
            revokedVersions: array_values(array_unique($revoked)),
            breakingChanges: (bool) ($data['breaking_changes'] ?? false),
            security: (bool) ($data['security'] ?? false),
        );
    }

    public function assertInstallableFrom(string $currentVersion): void
    {
        if ($this->schemaVersion !== 1) {
            throw new \RuntimeException("Unsupported update metadata schema: {$this->schemaVersion}.");
        }
        if (version_compare(PHP_VERSION, $this->minPhp, '<')) {
            throw new \RuntimeException("This release requires PHP {$this->minPhp} or newer.");
        }
        if (version_compare($this->version, $currentVersion, '<=')) {
            throw new \RuntimeException('The selected release is not newer than the installed version.');
        }
        $currentMajor = (int) explode('.', ltrim($currentVersion, 'v'))[0];
        $targetMajor = (int) explode('.', $this->version)[0];
        if ($currentMajor !== $targetMajor || $this->breakingChanges) {
            throw new \RuntimeException('This release requires a manual upgrade review.');
        }
        if ($this->minCoreFrom !== '' && version_compare($currentVersion, $this->minCoreFrom, '<')) {
            throw new \RuntimeException("Upgrade first to a release supported from {$this->minCoreFrom}.");
        }
        if ($this->maxCoreFrom !== '' && version_compare($currentVersion, $this->maxCoreFrom, '>')) {
            throw new \RuntimeException("This release only supports upgrades through {$this->maxCoreFrom}.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'channel' => $this->channel,
            'version' => $this->version,
            'released_at' => $this->releasedAt,
            'min_php' => $this->minPhp,
            'min_core_from' => $this->minCoreFrom,
            'max_core_from' => $this->maxCoreFrom,
            'zip_url' => $this->zipUrl,
            'signature_url' => $this->signatureUrl,
            'sha256' => $this->sha256,
            'size_bytes' => $this->sizeBytes,
            'release_notes_url' => $this->releaseNotesUrl,
            'revoked_versions' => $this->revokedVersions,
            'breaking_changes' => $this->breakingChanges,
            'security' => $this->security,
        ];
    }

    private static function httpsUrl(string $url, string $field): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new \RuntimeException("Update metadata {$field} must be an HTTPS URL.");
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException("Update metadata {$field} must not contain credentials.");
        }
        return $url;
    }
}
