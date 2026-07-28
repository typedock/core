<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class PreparedPackage
{
    public function __construct(
        public readonly string $stageRoot,
        public readonly string $appDir,
        public readonly string $publicDir,
        public readonly PackageManifest $manifest,
    ) {}
}
