<?php
declare(strict_types=1);

namespace TypeDock\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\Migration\Migrator;
use TypeDock\Core\Queue\Job;
use TypeDock\Core\Queue\JobQueue;
use TypeDock\Import\ImportMediaJob;
use TypeDock\Media\MediaService;
use TypeDock\Storage\LocalStorage;

/**
 * The failure half of image importing, which is the half that decides whether
 * a migration looks broken. Uses a `.invalid` host so nothing is fetched: the
 * TLD is reserved by RFC 2606 precisely so it can never resolve.
 */
final class ImportMediaJobTest extends TestCase
{
    private const DEAD_URL = 'https://never-resolves.invalid/wp-content/photo.jpg';

    private string $sqlitePath;
    private string $storageRoot;
    private \PDO $pdo;
    private MediaService $media;
    private ImportMediaJob $handler;

    protected function setUp(): void
    {
        $this->sqlitePath  = sys_get_temp_dir() . '/typedock-mediajob-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->storageRoot = sys_get_temp_dir() . '/typedock-mediajob-files-' . bin2hex(random_bytes(6));

        $this->pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $result = (new Migrator($this->pdo, 'sqlite', TYPEDOCK_ROOT . '/migrations'))->migrate();
        $this->assertSame([], $result['errors'], 'Migration errors: ' . json_encode($result['errors']));

        $this->media = new MediaService(
            $this->pdo,
            new LocalStorage(['root' => $this->storageRoot, 'url' => 'https://example.test/uploads'])
        );
        $this->handler = new ImportMediaJob($this->pdo, $this->media);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        if (is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
        if (is_dir($this->storageRoot)) {
            exec('rm -rf ' . escapeshellarg($this->storageRoot));
        }
    }

    public function testAnUnreachableSourceIsRetriedWhileAttemptsRemain(): void
    {
        $media = $this->media->reserve(self::DEAD_URL, 'batch-1');

        $this->expectException(\Throwable::class);
        $this->handler->handle($this->job((string) $media['id'], attempts: 1));
    }

    public function testTheLastAttemptParksTheRowAndPutsTheOriginalUrlBackInTheBody(): void
    {
        $media   = $this->media->reserve(self::DEAD_URL, 'batch-1');
        $mediaId = (string) $media['id'];
        $postId  = $this->insertPostWithImage($mediaId, (string) $media['url']);

        // Returns normally: there is nothing left to retry, and throwing would
        // only park a job nobody can act on.
        $this->handler->handle($this->job($mediaId, attempts: JobQueue::MAX_ATTEMPTS));

        $this->assertSame('failed', $this->media->find($mediaId)['status']);

        $stmt = $this->pdo->prepare('SELECT body FROM posts WHERE id = ?');
        $stmt->execute([$postId]);
        $image = json_decode((string) $stmt->fetchColumn(), true)['content'][0];

        $this->assertSame(self::DEAD_URL, $image['attrs']['src'], 'A hotlinked image beats a broken one');
        $this->assertArrayNotHasKey('mediaId', $image['attrs']);
    }

    public function testAnAlreadyFetchedRowIsANoOp(): void
    {
        $media = $this->media->reserve(self::DEAD_URL, 'batch-1');
        $this->pdo->prepare("UPDATE media SET status = 'ready' WHERE id = ?")->execute([$media['id']]);

        // At-least-once delivery means this happens routinely; it must not
        // re-download, and it must not throw.
        $this->handler->handle($this->job((string) $media['id'], attempts: 1));

        $this->assertSame('ready', $this->media->find((string) $media['id'])['status']);
    }

    private function job(string $mediaId, int $attempts): Job
    {
        return new Job(
            id: 'job-1',
            queue: 'default',
            handler: 'import.media',
            payload: ['media_id' => $mediaId],
            attempts: $attempts,
            batchId: 'batch-1',
        );
    }

    private function insertPostWithImage(string $mediaId, string $reservedUrl): string
    {
        $id   = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $body = json_encode([
            'type'    => 'doc',
            'content' => [
                ['type' => 'image', 'attrs' => ['src' => $reservedUrl, 'alt' => '', 'mediaId' => $mediaId]],
            ],
        ]);

        $this->pdo->prepare(
            "INSERT INTO posts (id, slug, title, body, status, locale, created_at, updated_at)
             VALUES (?, 'with-image', 'With Image', ?, 'published', 'en', ?, ?)"
        )->execute([$id, $body, $now, $now]);

        return $id;
    }
}
