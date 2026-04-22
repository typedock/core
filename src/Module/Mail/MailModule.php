<?php
declare(strict_types=1);

namespace TypeDock\Module\Mail;

use TypeDock\Contract\ModuleInterface;

class MailModule implements ModuleInterface
{
    public function register(): void
    {
        \Flight::map('mailer', function (): MailService {
            static $service = null;
            if ($service !== null) {
                return $service;
            }
            $service = new MailService();
            return $service;
        });
    }
}
