<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface MediaProcessor
{
    /**
     * Process uploaded media file. Returns the (possibly modified) file path.
     */
    public function process(string $filePath, string $mimeType): string;
}
