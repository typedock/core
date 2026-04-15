<?php
declare(strict_types=1);

namespace TypeDock\Module\Antispam;

use TypeDock\Contract\ModuleInterface;

class AntispamModule implements ModuleInterface
{
    public function register(): void
    {
        // TODO: Replace SpamCheckerInterface with reCAPTCHA/Turnstile
    }
}
