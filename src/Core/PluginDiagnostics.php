<?php
declare(strict_types=1);

namespace TypeDock\Core;

/**
 * Collects per-request plugin load problems (version mismatches, missing
 * main_class, manifest/code provides disagreement, ...) so the modules
 * settings page can surface them to admins instead of them disappearing
 * into error_log.
 */
class PluginDiagnostics
{
    /** @var array<int, array{slug:string,level:string,message:string}> */
    private array $entries = [];

    public function warn(string $slug, string $message): void
    {
        $this->entries[] = ['slug' => $slug, 'level' => 'warning', 'message' => $message];
    }

    public function error(string $slug, string $message): void
    {
        $this->entries[] = ['slug' => $slug, 'level' => 'error', 'message' => $message];
    }

    /** @return array<int, array{slug:string,level:string,message:string}> */
    public function all(): array
    {
        return $this->entries;
    }
}
