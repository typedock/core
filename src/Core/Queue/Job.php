<?php
declare(strict_types=1);

namespace TypeDock\Core\Queue;

/**
 * A claimed unit of work, as handed to a JobHandler.
 */
final class Job
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $queue,
        public readonly string $handler,
        public readonly array $payload,
        public readonly int $attempts,
        public readonly ?string $batchId,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        return new self(
            (string) $row['id'],
            (string) $row['queue'],
            (string) $row['handler'],
            is_array($payload) ? $payload : [],
            (int) $row['attempts'],
            isset($row['batch_id']) && $row['batch_id'] !== null ? (string) $row['batch_id'] : null,
        );
    }
}
