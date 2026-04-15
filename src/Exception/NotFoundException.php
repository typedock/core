<?php
declare(strict_types=1);

namespace TypeDock\Exception;

class NotFoundException extends TypeDockException
{
    public function __construct(string $message = 'Not Found', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
