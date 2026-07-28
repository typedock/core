<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class UpdateChecker
{
    public function __construct(
        private readonly string $channel,
        private readonly string $channelUrl,
        private readonly string $cachePath,
    ) {}

    public static function fromRuntime(): self
    {
        $channel = strtolower((string) \config('update.channel', 'stable'));
        $channels = (array) \config('update.channels', []);
        return new self(
            $channel,
            (string) ($channels[$channel] ?? ''),
            (string) \config('update.metadata_cache_path', TYPEDOCK_ROOT . '/storage/tmp/update-release.json'),
        );
    }

    public function check(): ReleaseMetadata
    {
        if ($this->channelUrl === '') {
            throw new \RuntimeException("No metadata URL is configured for the {$this->channel} update channel.");
        }
        $json = UpdateDownloader::readSmallHttps($this->channelUrl, 1_048_576);
        $release = ReleaseMetadata::fromJson($json, $this->channel);
        $this->writeCache($release);
        return $release;
    }

    public function cached(): ?ReleaseMetadata
    {
        if (!is_file($this->cachePath)) {
            return null;
        }
        try {
            return ReleaseMetadata::fromJson((string) file_get_contents($this->cachePath), $this->channel);
        } catch (\Throwable) {
            return null;
        }
    }

    private function writeCache(ReleaseMetadata $release): void
    {
        $dir = dirname($this->cachePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create the update metadata cache directory.');
        }
        $json = json_encode($release->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->cachePath, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Unable to cache update metadata.');
        }
    }
}
