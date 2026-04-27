<?php
declare(strict_types=1);

namespace TypeDock\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use TypeDock\Contract\MailerInterface;

/**
 * Core mail service — wraps PHPMailer for SMTP / sendmail / mail() delivery.
 * Plugins can replace this via provideSingle('mailer', new MyMailer()).
 *
 * Promoted from Module → Core per doc28 §1.3: email is a fundamental
 * capability, not an optional add-on. The on/off toggle (`MODULE_MAIL`) is
 * retired; email is always available.
 */
class MailService implements MailerInterface
{
    public function send(string $to, string $subject, string $body, array $options = []): bool
    {
        $mailer = $this->buildMailer();

        try {
            $mailer->addAddress($to);

            foreach ((array) ($options['cc'] ?? []) as $cc) {
                $mailer->addCC($cc);
            }
            foreach ((array) ($options['bcc'] ?? []) as $bcc) {
                $mailer->addBCC($bcc);
            }
            if (!empty($options['reply_to'])) {
                $mailer->addReplyTo((string) $options['reply_to']);
            }

            $isHtml = $options['html'] ?? str_contains($body, '<');
            $mailer->isHTML((bool) $isHtml);
            $mailer->Subject = $subject;
            $mailer->Body    = $body;
            if ($isHtml) {
                $mailer->AltBody = strip_tags($body);
            }

            foreach ($options['attachments'] ?? [] as $att) {
                if (is_string($att)) {
                    $mailer->addAttachment($att);
                } elseif (is_array($att) && !empty($att['path'])) {
                    $mailer->addAttachment($att['path'], $att['name'] ?? '');
                }
            }

            return $mailer->send();
        } catch (PHPMailerException $e) {
            error_log('[TypeDock\\Mail] Send failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Render a Latte template and send as HTML.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function sendTemplate(string $to, string $subject, string $template, array $data = [], array $options = []): bool
    {
        try {
            /** @var \TypeDock\Theme\LatteFactory $latte */
            $latte = \Flight::latte();
            $body  = $latte->renderToString($template, $data);
        } catch (\Throwable $e) {
            error_log('[TypeDock\\Mail] Template render failed: ' . $e->getMessage());
            return false;
        }

        $options['html'] = $options['html'] ?? true;
        return $this->send($to, $subject, $body, $options);
    }

    private function buildMailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'UTF-8';

        $driver = (string) $this->mailOption('mail.default', config('mail.default', 'php'));
        if ($driver === 'smtp') {
            $smtp = [
                'host' => $this->mailOption('mail.smtp.host', config('mail.smtp.host', 'localhost')),
                'port' => $this->mailOption('mail.smtp.port', config('mail.smtp.port', 587)),
                'username' => $this->mailOption('mail.smtp.username', config('mail.smtp.username', '')),
                'password' => $this->mailOption('mail.smtp.password', config('mail.smtp.password', '')),
                'encryption' => $this->mailOption('mail.smtp.encryption', config('mail.smtp.encryption', 'tls')),
            ];
            $mailer->isSMTP();
            $mailer->Host = (string) ($smtp['host'] ?? 'localhost');
            $mailer->Port = (int) ($smtp['port'] ?? 587);
            if (!empty($smtp['username'])) {
                $mailer->SMTPAuth = true;
                $mailer->Username = (string) $smtp['username'];
                $mailer->Password = (string) ($smtp['password'] ?? '');
            }
            if (!empty($smtp['encryption'])) {
                $mailer->SMTPSecure = (string) $smtp['encryption'];
            }
        } elseif ($driver === 'sendmail') {
            $mailer->isSendmail();
        } else {
            $mailer->isMail();
        }

        $mailer->setFrom(
            (string) $this->mailOption('mail.from_email', config('mail.from_email', 'noreply@example.com')),
            (string) $this->mailOption('mail.from_name', config('mail.from_name', 'TypeDock'))
        );
        return $mailer;
    }

    private function mailOption(string $key, mixed $default): mixed
    {
        try {
            return site_option($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
