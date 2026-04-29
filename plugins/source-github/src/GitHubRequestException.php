<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SourceGitHub;

final class GitHubRequestException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $responseBody = '',
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : 'GitHub request failed with status ' . $statusCode . '.', $statusCode, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function responseBody(): string
    {
        return $this->responseBody;
    }

    public function githubMessage(): string
    {
        $json = json_decode($this->responseBody, true);
        if (is_array($json) && isset($json['message']) && is_scalar($json['message'])) {
            return trim((string) $json['message']);
        }
        return '';
    }
}
