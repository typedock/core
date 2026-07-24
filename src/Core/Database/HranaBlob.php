<?php
declare(strict_types=1);

namespace TypeDock\Core\Database;

/**
 * Marks a bound value for Hrana's base64-encoded BLOB representation.
 *
 * @internal
 */
final readonly class HranaBlob
{
    public function __construct(public string $data)
    {
    }
}
