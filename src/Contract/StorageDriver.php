<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface StorageDriver
{
    public function put(string $path, string $contents): bool;
    public function putFile(string $path, string $localPath): bool;
    public function get(string $path): ?string;
    public function exists(string $path): bool;
    public function delete(string $path): bool;
    public function url(string $path): string;
    /** @return array<string> */
    public function listFiles(string $directory): array;
}
