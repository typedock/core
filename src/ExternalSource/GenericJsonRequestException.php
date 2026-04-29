<?php
declare(strict_types=1);

namespace TypeDock\ExternalSource;

final class GenericJsonRequestException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $responseBody = '',
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : 'Generic JSON request failed with status ' . $statusCode . '.', $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function responseBody(): string
    {
        return $this->responseBody;
    }

    public function jsonMessage(): string
    {
        $json = json_decode($this->responseBody, true);
        if (is_array($json)) {
            foreach (['message', 'error', 'detail'] as $key) {
                if (isset($json[$key]) && is_scalar($json[$key])) {
                    return trim((string) $json[$key]);
                }
            }
        }
        return '';
    }
}
