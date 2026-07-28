<?php
declare(strict_types=1);

namespace TypeDock\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TypeDock\Core\Migration\Migrator;
use TypeDock\Core\Queue\JobQueue;
use TypeDock\Import\ImporterRegistry;
use TypeDock\Import\ImportOptions;
use TypeDock\Import\ImportService;
use TypeDock\Media\MediaService;
use TypeDock\Plugin\ImportWordPress\WxrImporter;
use TypeDock\Storage\LocalStorage;

/**
 * The two ways an imported row can end up with a URL that does not resolve:
 * a page whose path was flattened into its slug, and a "featured image" that
 * is a PDF.
 *
 * Both were reported against 1.0.0-rc6 from a real migration.
 */
final class WordPressImportUrlsTest extends TestCase
{
    private const FIXTURE = TYPEDOCK_ROOT . '/tests/fixtures/wxr/paths-and-pdf.xml';

    private string $sqlitePath;
    private string $storageRoot;
    private \PDO $pdo;
    private ImportService $service;

    protected function setUp(): void
    {
        if (!extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-xmlreader is required to read WXR files.');
        }
        if (!class_exists(WxrImporter::class)) {
            $this->markTestSkipped('WordPress importer plugin is not autoloadable.');
        }

        $this->sqlitePath = sys_get_temp_dir() . '/typedock-wxr-urls-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $result = (new Migrator($this->pdo, 'sqlite', TYPEDOCK_ROOT . '/migrations'))->migrate();
        $this->assertSame([], $result['errors'], 'Migration errors: ' . json_encode($result['errors']));

        $this->storageRoot = sys_get_temp_dir() . '/typedock-wxr-urls-uploads-' . bin2hex(random_bytes(6));
        $media = new MediaService(
            $this->pdo,
            new LocalStorage(['root' => $this->storageRoot, 'url' => 'https://new.example.test/uploads'])
        );

        $registry = new ImporterRegistry();
        $registry->register(new WxrImporter());
        $this->service = new ImportService($this->pdo, $registry, $media, new JobQueue($this->pdo));
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

    public function testPageKeepsItsHierarchicalPath(): void
    {
        $this->runImport();

        $page = $this->postBySlug('showcase/customer-a');

        $this->assertNotNull($page, 'A page slug is its URL path and must survive the import');
        $this->assertSame('page', $page['post_type']);
    }

    public function testPostSlugIsFlattenedToASingleSegment(): void
    {
        $this->runImport();

        $this->assertNotNull(
            $this->postBySlug('showcase-showcase-001'),
            'Posts live under one archive segment, so a slash cannot survive in their slug'
        );
        $this->assertNull($this->postBySlug('showcase/showcase-001'));
    }

    public function testJapanesePermalinkIsKeptRatherThanFlattenedToATimestamp(): void
    {
        $this->runImport();

        $post = $this->postBySlug('お知らせ');

        $this->assertNotNull(
            $post,
            'WordPress percent-encodes non-ASCII post_name; decoding it is what keeps inbound links working'
        );
        $this->assertSame('post', $post['post_type']);
    }

    public function testJapaneseCategoryIsStoredInTheFormARequestArrivesIn(): void
    {
        $this->runImport();

        $slug = $this->pdo->query('SELECT slug FROM categories LIMIT 1')->fetchColumn();

        $this->assertSame('お知らせ', $slug);
        // Flight urldecodes route parameters, so this is literally what
        // CategoryService::findBySlug() will be handed for /category/お知らせ.
        $this->assertSame($slug, urldecode(ltrim(slug_path((string) $slug), '/')));
    }

    public function testJapaneseSlugIsPercentEncodedOnTheWayIntoAUrl(): void
    {
        $importId = $this->runImport();

        $map = [];
        foreach ($this->service->redirectMap($importId) as [$from, $to]) {
            $map[$from] = $to;
        }

        $this->assertSame(
            '/blog/%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B',
            $map['/%E3%81%8A%E7%9F%A5%E3%82%89%E3%81%9B/'],
            'Stored decoded, emitted encoded'
        );
    }

    public function testPdfFeaturedImageUsesTheGeneratedPreview(): void
    {
        $this->runImport();

        $media = $this->featuredMediaOf('brochure');

        $this->assertNotNull($media, 'The post should have a featured image');
        $this->assertSame(
            'https://old.example.com/wp-content/uploads/2021/03/sample-pdf.jpg',
            $media['source_url'],
            'WordPress displays the JPEG it generated beside the PDF, not the PDF'
        );
        $this->assertSame('image/jpeg', $media['mime_type']);
    }

    public function testPdfWithNoPreviewIsNotMadeAFeaturedImage(): void
    {
        $importId = $this->runImport();

        $this->assertNull(
            $this->featuredMediaOf('plain-pdf'),
            'A PDF as og:image renders as a broken thumbnail everywhere'
        );

        $summary = json_decode((string) $this->service->find($importId)['summary'], true);

        $this->assertSame(1, $summary['featured_non_image']);
        $this->assertSame(1, $summary['featured_resolved'], 'The previewed PDF still resolves');
        $this->assertNotEmpty(array_filter(
            $summary['warnings'],
            static fn (string $w): bool => str_contains($w, 'not images')
        ), 'Refusing a featured image has to be reported, never silent');
    }

    public function testThePdfItselfStaysInTheMediaLibrary(): void
    {
        $this->runImport();

        $urls = $this->pdo->query('SELECT source_url FROM media')->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains(
            'https://old.example.com/wp-content/uploads/2021/03/plain.pdf',
            $urls,
            'The file was referenced, so it is imported — it just is not the thumbnail'
        );
    }

    // -----------------------------------------------------------------

    private function runImport(): string
    {
        $importId = $this->service->create('wordpress', self::FIXTURE, new ImportOptions());
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

    /** @return array<string, mixed>|null */
    private function featuredMediaOf(string $slug): ?array
    {
        $post = $this->postBySlug($slug);
        $this->assertNotNull($post, "Missing post: {$slug}");

        $stmt = $this->pdo->prepare(
            'SELECT m.* FROM seo_meta s JOIN media m ON m.id = s.og_image_id
              WHERE s.target_type = ? AND s.target_id = ? LIMIT 1'
        );
        $stmt->execute([$post['post_type'], $post['id']]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
