<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class PreflightReport
{
    /**
     * @param list<PreflightIssue> $issues
     * @param list<array{type:string,slug:string,status:string,message:string}> $ownership
     */
    public function __construct(
        public readonly InstallationProfile $profile,
        public readonly PackageManifest $manifest,
        public readonly array $issues,
        public readonly array $ownership,
    ) {}

    public function canApplyUpdates(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === 'error') {
                return false;
            }
        }
        return true;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->severity === 'warning') {
                return true;
            }
        }
        return false;
    }
}
