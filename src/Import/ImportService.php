<?php
declare(strict_types=1);

namespace TypeDock\Import;

use TypeDock\Core\Queue\JobQueue;
use TypeDock\Media\MediaService;

/**
 * Owns an import run: its row in `imports`, its resume point, and the lock
 * that stops two workers from ingesting the same file at once.
 *
 * The unit of work is `advance()`, which ingests until a deadline and returns.
 * A CLI run simply calls it until it reports done; a browser-driven or cron
 * worker calls it once per tick. Neither needs a different code path, and
 * neither depends on the request surviving to the end of the file.
 */
final class ImportService
{
    private const LEASE_SECONDS = 300;

    /** Keep the stored warning list bounded — it is diagnostics, not a log. */
    private const MAX_WARNINGS = 200;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ImporterRegistry $registry,
        private readonly ?MediaService $media = null,
        private readonly ?JobQueue $queue = null,
    ) {
    }

    /**
     * Register an import run. Does not read the file.
     */
    public function create(string $importerKey, string $file, ImportOptions $options, ?string $userId = null): string
    {
        $this->importerOrFail($importerKey);

        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = $this->now();

        $this->pdo->prepare(
            'INSERT INTO imports (id, importer, source_name, source_file, status, processed, options, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?)'
        )->execute([
            $id,
            $importerKey,
            basename($file),
            $file,
            'ready',
            json_encode($options->toArray()),
            $userId,
            $now,
            $now,
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM imports WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Dry run: report what the file contains without writing anything.
     */
    public function scan(string $importerKey, string $file): ImportScan
    {
        return $this->importerOrFail($importerKey)->scan($file);
    }

    /**
     * Ingest documents until the file is exhausted or the deadline passes.
     *
     * @param float|null $deadline microtime(true) value to stop at; null runs to completion.
     * @return array{processed:int, created:int, updated:int, failed:int, done:bool}
     */
    public function advance(string $importId, ?float $deadline = null): array
    {
        $row = $this->find($importId);
        if ($row === null) {
            throw new \RuntimeException("Import not found: {$importId}");
        }
        if ((string) $row['status'] === 'done') {
            return ['processed' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'done' => true];
        }

        $this->acquireLeaseOrFail($importId);

        $importerKey = (string) $row['importer'];
        $importer    = $this->importerOrFail($importerKey);
        $file        = (string) $row['source_file'];
        $options     = ImportOptions::fromArray($this->decode($row['options']));
        $summary     = $this->decode($row['summary']);
        $processed   = (int) $row['processed'];

        $writer  = new ImportWriter($this->pdo, $options, $this->media, $this->queue);
        $created = 0;
        $updated = 0;
        $failed  = 0;
        $exhausted = false;

        try {
            $documents = $importer->documents($file, $processed);
            foreach ($documents as $document) {
                try {
                    $result = $writer->write($document, $importerKey, $importId);
                    $result['action'] === 'created' ? $created++ : $updated++;
                } catch (\Throwable $e) {
                    // One malformed post must not abandon the other 4,999.
                    $failed++;
                    $summary = $this->addWarning(
                        $summary,
                        sprintf('%s "%s": %s', $document->externalId, $document->title, $e->getMessage())
                    );
                }

                foreach ($document->warnings as $warning) {
                    $summary = $this->addWarning($summary, $warning);
                }
                $summary['unmapped_nodes'] = (int) ($summary['unmapped_nodes'] ?? 0) + $document->unmappedNodes;

                $processed++;

                if ($deadline !== null && microtime(true) >= $deadline) {
                    break;
                }
            }
            // A generator that has been fully consumed reports invalid; a
            // generator we broke out of is still valid and mid-stream.
            $exhausted = !$documents->valid();
        } catch (\Throwable $e) {
            $summary['error'] = $e->getMessage();
            $this->persist($importId, $processed, 'failed', $this->tally($summary, $created, $updated, $failed));
            throw $e;
        }

        if ($exhausted) {
            $summary['parents_resolved'] = $writer->resolvePendingParents($importerKey, $importId);
        }

        $this->persist(
            $importId,
            $processed,
            $exhausted ? 'done' : 'ready',
            $this->tally($summary, $created, $updated, $failed)
        );

        return [
            'processed' => $processed,
            'created'   => $created,
            'updated'   => $updated,
            'failed'    => $failed,
            'done'      => $exhausted,
        ];
    }

    /**
     * Remove everything an import created. The batch id makes this one
     * statement, which is the whole reason the column exists.
     */
    public function undo(string $importId): int
    {
        // Drop queued downloads first: there is no point fetching images for
        // posts that are about to disappear, and a job that outlives its media
        // row is just a guaranteed failure in the log.
        $this->pdo->prepare('DELETE FROM jobs WHERE batch_id = ?')->execute([$importId]);

        if ($this->media !== null) {
            $rows = $this->pdo->prepare('SELECT id FROM media WHERE import_batch_id = ?');
            $rows->execute([$importId]);
            foreach ($rows->fetchAll(\PDO::FETCH_COLUMN) as $mediaId) {
                $this->media->delete((string) $mediaId);
            }
        }

        $stmt = $this->pdo->prepare('DELETE FROM posts WHERE import_batch_id = ?');
        $stmt->execute([$importId]);

        $this->pdo->prepare('UPDATE imports SET status = ?, processed = 0, updated_at = ? WHERE id = ?')
            ->execute(['cancelled', $this->now(), $importId]);

        return $stmt->rowCount();
    }

    private function importerOrFail(string $key): ImporterInterface
    {
        $importer = $this->registry->get($key);
        if ($importer === null) {
            $known = implode(', ', $this->registry->keys()) ?: '(none registered)';
            throw new \RuntimeException("Unknown importer '{$key}'. Available: {$known}");
        }

        return $importer;
    }

    /**
     * Advisory lock so a browser tick and a cron worker cannot ingest the same
     * file into the same rows simultaneously. Same optimistic pattern as the
     * job queue: whoever's conditional UPDATE lands owns the run.
     */
    private function acquireLeaseOrFail(string $importId): void
    {
        $now  = $this->now();
        $stmt = $this->pdo->prepare(
            "UPDATE imports
                SET status = 'running', lease_until = ?, updated_at = ?
              WHERE id = ?
                AND (status <> 'running' OR lease_until IS NULL OR lease_until < ?)"
        );
        $stmt->execute([$this->at(self::LEASE_SECONDS), $now, $importId, $now]);

        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException("Import {$importId} is already running elsewhere.");
        }
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function tally(array $summary, int $created, int $updated, int $failed): array
    {
        $summary['created'] = (int) ($summary['created'] ?? 0) + $created;
        $summary['updated'] = (int) ($summary['updated'] ?? 0) + $updated;
        $summary['failed']  = (int) ($summary['failed'] ?? 0) + $failed;

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function addWarning(array $summary, string $warning): array
    {
        $warnings = $summary['warnings'] ?? [];
        if (!is_array($warnings)) {
            $warnings = [];
        }
        if (count($warnings) < self::MAX_WARNINGS && !in_array($warning, $warnings, true)) {
            $warnings[] = $warning;
        }
        $summary['warnings'] = $warnings;

        return $summary;
    }

    /** @param array<string, mixed> $summary */
    private function persist(string $importId, int $processed, string $status, array $summary): void
    {
        $this->pdo->prepare(
            'UPDATE imports SET processed = ?, status = ?, summary = ?, lease_until = NULL, updated_at = ? WHERE id = ?'
        )->execute([$processed, $status, json_encode($summary), $this->now(), $importId]);
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function at(int $offsetSeconds): string
    {
        return (new \DateTimeImmutable())->modify("+{$offsetSeconds} seconds")->format('Y-m-d H:i:s');
    }
}
