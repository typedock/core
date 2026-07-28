<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class UpdateState
{
    public function __construct(private readonly string $path) {}

    /**
     * @param array<string, mixed> $data
     */
    public function write(array $data): void
    {
        $data['updated_at'] = gmdate(\DateTimeInterface::ATOM);
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create the update state directory.');
        }
        $tmp = $this->path . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to persist update state.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($this->path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function requireToken(string $token): array
    {
        $state = $this->read();
        $expected = is_array($state) ? (string) ($state['token'] ?? '') : '';
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            throw new \RuntimeException('Update state token is invalid or expired.');
        }
        return $state;
    }
}
