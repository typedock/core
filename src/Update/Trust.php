<?php
declare(strict_types=1);

namespace TypeDock\Update;

final class Trust
{
    // Replace both keys before publishing an update-capable release. The
    // primary key is held by the protected release environment; the encrypted
    // recovery key stays offline and is used only to rotate a lost or
    // compromised primary key.
    public const PRIMARY_MINISIGN_PUBLIC_KEY = '';
    public const RECOVERY_MINISIGN_PUBLIC_KEY = '';

    /**
     * @return list<string>
     */
    public static function publicKeys(): array
    {
        $primary = self::PRIMARY_MINISIGN_PUBLIC_KEY;
        $recovery = self::RECOVERY_MINISIGN_PUBLIC_KEY;
        if (function_exists('config')) {
            $primary = (string) \config('update.minisign_public_key', $primary);
            $recovery = (string) \config('update.recovery_minisign_public_key', $recovery);
        }

        return array_values(array_unique(array_filter(
            [trim($primary), trim($recovery)],
            static fn(string $key): bool => $key !== '',
        )));
    }
}
