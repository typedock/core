<?php
declare(strict_types=1);

namespace TypeDock\Contract;

/**
 * Optional extension for resolvers that distinguish `/?p=123` from `/`.
 *
 * Ordinary RedirectResolver implementations continue to receive only the
 * path, preserving regex and custom resolver behaviour when tracking
 * parameters are present.
 */
interface QueryAwareRedirectResolver extends RedirectResolver
{
    /**
     * @return array{0: string, 1: int}|null
     */
    public function resolveRequestTarget(string $requestTarget): ?array;
}
