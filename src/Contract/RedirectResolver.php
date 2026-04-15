<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface RedirectResolver
{
    /**
     * Try to resolve a redirect for the given path.
     * Returns [target_url, status_code] or null if not handled.
     *
     * @return array{0: string, 1: int}|null
     */
    public function resolve(string $sourcePath): ?array;
}
