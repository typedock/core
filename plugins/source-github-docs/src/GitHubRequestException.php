<?php
declare(strict_types=1);

namespace TypeDock\Plugin\SourceGitHubDocs;

final class GitHubRequestException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $body = ''
    ) {
        parent::__construct('GitHub returned HTTP ' . $statusCode, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function githubMessage(): string
    {
        if ($this->body === '') {
            return '';
        }

        $json = json_decode($this->body, true);
        if (is_array($json) && isset($json['message']) && is_scalar($json['message'])) {
            return (string) $json['message'];
        }

        return '';
    }
}
