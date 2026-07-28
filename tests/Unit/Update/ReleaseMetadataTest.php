<?php
declare(strict_types=1);

namespace TypeDock\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use TypeDock\Update\ReleaseMetadata;

final class ReleaseMetadataTest extends TestCase
{
    public function testParsesAndAcceptsNewerCompatibleRelease(): void
    {
        $release = ReleaseMetadata::fromJson($this->json(), 'rc');
        $release->assertInstallableFrom('1.0.0-rc6');

        self::assertSame('1.0.0-rc7', $release->version);
        self::assertSame(1234, $release->sizeBytes);
    }

    public function testRejectsRollbackAndNonHttpsArtifact(): void
    {
        $release = ReleaseMetadata::fromJson($this->json(), 'rc');
        $this->expectExceptionMessage('not newer');
        $release->assertInstallableFrom('1.0.0');
    }

    public function testRejectsChannelMismatch(): void
    {
        $this->expectExceptionMessage('channel mismatch');
        ReleaseMetadata::fromJson($this->json(), 'stable');
    }

    private function json(): string
    {
        return json_encode([
            'schema_version' => 1,
            'channel' => 'rc',
            'version' => '1.0.0-rc7',
            'min_php' => '8.2.0',
            'min_core_from' => '1.0.0-rc6',
            'zip_url' => 'https://example.com/typedock.zip',
            'signature_url' => 'https://example.com/typedock.zip.minisig',
            'sha256' => str_repeat('a', 64),
            'size_bytes' => 1234,
            'release_notes_url' => 'https://example.com/releases/rc7',
        ], JSON_THROW_ON_ERROR);
    }
}
