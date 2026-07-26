<?php
declare(strict_types=1);

namespace TypeDock\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\Migration\Migrator;
use TypeDock\Import\ImporterRegistry;
use TypeDock\Import\ImportOptions;
use TypeDock\Core\Queue\JobQueue;
use TypeDock\Import\ImportService;
use TypeDock\Media\MediaService;
use TypeDock\Plugin\ImportWordPress\WxrImporter;
use TypeDock\Storage\LocalStorage;

/**
 * End-to-end: a WXR fixture through the WordPress importer into real rows.
 *
 * The fixture is deliberately awkward — classic and Gutenberg bodies, a child
 * page before its parent, a trashed post, an attachment, an auto-generated
 * excerpt — because those are the shapes that break importers.
 */
final class WordPressImportTest extends TestCase
{
    private const FIXTURE = TYPEDOCK_ROOT . '/tests/fixtures/wxr/sample.xml';

    private string $sqlitePath;
    private string $storageRoot;
    private \PDO $pdo;
    private ImportService $service;
    private JobQueue $queue;

    protected function setUp(): void
    {
        if (!extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-xmlreader is required to read WXR files.');
        }
        // The importer is a drop-in plugin: without its autoload registered by
        // PluginLoader the class only resolves once composer has seen it.
        if (!class_exists(WxrImporter::class)) {
            $this->markTestSkipped('WordPress importer plugin is not autoloadable.');
        }

        $this->sqlitePath = sys_get_temp_dir() . '/typedock-wxr-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $result = (new Migrator($this->pdo, 'sqlite', TYPEDOCK_ROOT . '/migrations'))->migrate();
        $this->assertSame([], $result['errors'], 'Migration errors: ' . json_encode($result['errors']));

        $this->storageRoot = sys_get_temp_dir() . '/typedock-wxr-uploads-' . bin2hex(random_bytes(6));
        $media = new MediaService(
            $this->pdo,
            new LocalStorage(['root' => $this->storageRoot, 'url' => 'https://new.example.test/uploads'])
        );

        $registry = new ImporterRegistry();
        $registry->register(new WxrImporter());
        $this->queue   = new JobQueue($this->pdo);
        $this->service = new ImportService($this->pdo, $registry, $media, $this->queue);
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

    public function testScanReportsContentWithoutWriting(): void
    {
        $scan = $this->service->scan('wordpress', self::FIXTURE);

        $this->assertSame(3, $scan->counts['post'], 'Two published posts plus the scheduled one');
        $this->assertSame(2, $scan->counts['page']);
        $this->assertSame(1, $scan->counts['attachment']);
        $this->assertSame(1, $scan->counts['skipped'], 'The trashed post');
        $this->assertSame('https://old.example.com', $scan->sourceSiteUrl);

        $this->assertSame(1, $scan->unmappedNodes, 'The <table> cannot become a block');
        $this->assertNotSame([], $scan->warnings);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());
    }

    public function testImportCreatesPostsPagesAndTerms(): void
    {
        $this->runImport();

        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());
        $this->assertNull($this->postBySlug('deleted-thing'), 'Trashed posts are not imported');
        $this->assertNull($this->postBySlug('photo'), 'Attachments are not posts');

        $classic = $this->postBySlug('hello-classic');
        $this->assertNotNull($classic);
        $this->assertSame('post', $classic['post_type']);
        $this->assertSame('published', $classic['status']);
        $this->assertSame('2019-05-01 10:00:00', $classic['published_at']);
        $this->assertSame('wordpress', $classic['external_source']);
        $this->assertSame('11', $classic['external_id']);

        $this->assertSame(
            ['news', 'releases'],
            $this->pdo->query('SELECT slug FROM categories ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN)
        );
        $this->assertSame(
            ['php'],
            $this->pdo->query('SELECT slug FROM tags ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    public function testCategoryHierarchyComesFromTheChannelHeader(): void
    {
        $this->runImport();

        $stmt = $this->pdo->query(
            "SELECT c.slug, p.slug AS parent_slug
               FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
              WHERE c.slug = 'releases'"
        );
        $row = $stmt->fetch();

        $this->assertSame('news', $row['parent_slug']);
    }

    public function testChildPageIsLinkedToAParentThatArrivedLater(): void
    {
        $this->runImport();

        $child  = $this->postBySlug('team');
        $parent = $this->postBySlug('about');

        $this->assertNotNull($child);
        $this->assertNotNull($parent);
        $this->assertSame($parent['id'], $child['parent_id'], 'Forward parent reference must be resolved');
    }

    public function testFutureDatedPostBecomesScheduled(): void
    {
        $this->runImport();

        $post = $this->postBySlug('scheduled-announcement');

        $this->assertSame('scheduled', $post['status']);
        $this->assertSame('2099-01-01 00:00:00', $post['scheduled_at']);
        $this->assertNull($post['published_at']);
    }

    public function testClassicBodyIsSplitIntoParagraphs(): void
    {
        $this->runImport();

        $body = json_decode((string) $this->postBySlug('hello-classic')['body'], true);
        $types = array_column($body['content'], 'type');

        $this->assertSame(['paragraph', 'paragraph', 'componentBlock'], $types);
        $this->assertSame(
            'custom_html',
            $body['content'][2]['attrs']['component'],
            'The table is preserved as raw HTML rather than dropped'
        );
        $this->assertStringContainsString('<table>', $body['content'][2]['attrs']['params']['html']);
    }

    public function testGutenbergBodyKeepsHeadingImageAndList(): void
    {
        $this->runImport();

        $body  = json_decode((string) $this->postBySlug('second-post')['body'], true);
        $types = array_column($body['content'], 'type');

        $this->assertSame(['paragraph', 'heading', 'image', 'bulletList'], $types);
        $this->assertSame(3, $body['content'][1]['attrs']['level']);
        $this->assertSame('The caption', $body['content'][2]['attrs']['caption']);
        $this->assertSame('A photo', $body['content'][2]['attrs']['alt']);
        $this->assertCount(2, $body['content'][3]['content']);
    }

    public function testImagesAreReservedInTheMediaLibraryBeforeTheBodyIsWritten(): void
    {
        $importId = $this->runImport();

        $image = json_decode((string) $this->postBySlug('second-post')['body'], true)['content'][2];

        $this->assertArrayHasKey('mediaId', $image['attrs']);
        $this->assertStringStartsWith('https://new.example.test/uploads/import/', $image['attrs']['src']);

        $stmt = $this->pdo->prepare('SELECT * FROM media WHERE id = ?');
        $stmt->execute([$image['attrs']['mediaId']]);
        $media = $stmt->fetch();

        $this->assertSame('pending', $media['status'], 'The row exists before the file does');
        $this->assertSame($importId, $media['import_batch_id']);
        $this->assertSame(
            'https://old.example.com/wp-content/uploads/2019/06/photo.jpg',
            $media['source_url'],
            'The -300x200 thumbnail suffix is normalised away to fetch the original'
        );
        $this->assertStringEndsWith('.jpg', (string) $media['path']);
    }

    public function testEachImageIsQueuedForDownloadExactlyOnce(): void
    {
        $this->runImport();

        $stmt = $this->pdo->query("SELECT payload FROM jobs WHERE handler = 'import.media'");
        $payloads = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertCount(1, $payloads);
        $this->assertSame(1, $this->queue->dueCount());
    }

    public function testReimportDoesNotDuplicateMediaRows(): void
    {
        $this->runImport();
        $this->runImport();

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM media')->fetchColumn());
    }

    public function testSkipMediaLeavesImagesOnTheSourceSite(): void
    {
        $importId = $this->service->create(
            'wordpress',
            self::FIXTURE,
            new ImportOptions(fetchMedia: false)
        );
        do {
            $result = $this->service->advance($importId);
        } while (!$result['done']);

        $image = json_decode((string) $this->postBySlug('second-post')['body'], true)['content'][2];

        $this->assertStringStartsWith('https://old.example.com/', $image['attrs']['src']);
        $this->assertArrayNotHasKey('mediaId', $image['attrs']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM media')->fetchColumn());
    }

    public function testAutoGeneratedExcerptIsNotImported(): void
    {
        $this->runImport();

        $this->assertNull($this->postBySlug('second-post')['excerpt'], 'WordPress "[…]" excerpts are generated');
        $this->assertSame('Everything about us.', $this->postBySlug('about')['excerpt']);
    }

    public function testAuthorIsMatchedByEmail(): void
    {
        $userId = $this->insertUser('hanako@example.com');
        $this->runImport();

        $this->assertSame($userId, $this->postBySlug('hello-classic')['author_id']);
        $this->assertNull($this->postBySlug('second-post')['author_id'], 'No account for that author');
    }

    public function testReimportUpdatesInPlaceAndAddsNoRevisions(): void
    {
        $this->runImport();
        $firstId = $this->postBySlug('hello-classic')['id'];

        $this->runImport();

        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());
        $this->assertSame($firstId, $this->postBySlug('hello-classic')['id']);
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM post_revisions')->fetchColumn(),
            'An import is not an edit — it must not bury the real revision history'
        );
    }

    public function testUndoRemovesEverythingTheImportCreated(): void
    {
        $importId = $this->runImport();

        $removed = $this->service->undo($importId);

        $this->assertSame(5, $removed);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM media')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    public function testResumingFromADeadlinePicksUpWhereItStopped(): void
    {
        $importId = $this->service->create('wordpress', self::FIXTURE, new ImportOptions());

        // A deadline already in the past stops after the first document.
        $first = $this->service->advance($importId, microtime(true) - 1);
        $this->assertFalse($first['done']);
        $this->assertSame(1, $first['processed']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());

        do {
            $next = $this->service->advance($importId);
        } while (!$next['done']);

        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn());
        $this->assertSame('done', (string) $this->service->find($importId)['status']);
    }

    public function testConcurrentRunIsRefused(): void
    {
        $importId = $this->service->create('wordpress', self::FIXTURE, new ImportOptions());

        $this->pdo->prepare("UPDATE imports SET status = 'running', lease_until = ? WHERE id = ?")
            ->execute([(new \DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s'), $importId]);

        $this->expectExceptionMessageMatches('/already running/');
        $this->service->advance($importId);
    }

    public function testFileWithADoctypeIsRefused(): void
    {
        $path = sys_get_temp_dir() . '/typedock-bomb-' . bin2hex(random_bytes(4)) . '.xml';
        file_put_contents($path, "<?xml version=\"1.0\"?>\n<!DOCTYPE rss [<!ENTITY a \"aaaa\">]>\n<rss><channel></channel></rss>");

        try {
            $this->expectExceptionMessageMatches('/DOCTYPE/');
            $this->service->scan('wordpress', $path);
        } finally {
            @unlink($path);
        }
    }

    private function runImport(bool $asDraft = false): string
    {
        $importId = $this->service->create('wordpress', self::FIXTURE, new ImportOptions(asDraft: $asDraft));
        do {
            $result = $this->service->advance($importId);
        } while (!$result['done']);

        return $importId;
    }

    /** @return array<string, mixed>|null */
    private function postBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM posts WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function insertUser(string $email): string
    {
        $id  = \Ramsey\Uuid\Uuid::uuid7()->toString();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO users (id, email, password_hash, name, role, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $email, 'x', 'Hanako', 'admin', $now, $now]);

        return $id;
    }
}
