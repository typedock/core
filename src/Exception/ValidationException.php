<?php
declare(strict_types=1);

namespace TypeDock\Exception;

class ValidationException extends TypeDockException
{
    /** @param array<string, string[]> $errors */
    public function __construct(
        private readonly array $errors,
        string $message = 'Validation failed',
        int $code = 422,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
