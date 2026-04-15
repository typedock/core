<?php
declare(strict_types=1);

namespace TypeDock\Contract;

interface MailerInterface
{
    public function send(
        string $to,
        string $subject,
        string $body,
        array $options = []
    ): bool;
}
