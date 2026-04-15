<?php
declare(strict_types=1);

namespace TypeDock\Mail;

use TypeDock\Contract\MailerInterface;

class PhpMailer implements MailerInterface
{
    public function send(string $to, string $subject, string $body, array $options = []): bool
    {
        $fromEmail = config('mail.from_email', 'noreply@example.com');
        $fromName  = config('mail.from_name', 'TypeDock');

        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'MIME-Version: 1.0',
        ];

        return mail(
            $to,
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            $body,
            implode("\r\n", $headers)
        );
    }
}
