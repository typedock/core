<?php
declare(strict_types=1);

namespace TypeDock\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TypeDock\Content\PostService;
use TypeDock\Core\Migration\Migrator;

/**
 * Defense-in-depth tests for PostService::update (doc24 #1 ownership).
 *
 * The controllers (PostController/PageController) check ownership before
 * calling the service, but a future refactor or a controller bug could let
 * an attacker tamper with `author_id`, `post_type`, or `locale` via the
 * data array. This test pins the rule that the service refuses to honour
 * those identity columns from the update payload — they're locked to
 * whatever was set at create() time.
 *
 * Uses the migration runner against a fresh SQLite DB so the test exercises
 * the actual schema, not a stub.
 */
final class PostServiceOwnershipTest extends TestCase
{
    private \PDO $pdo;
    private PostService $service;
    private string $sqlitePath;
    private const AUTHOR_A = '00000000-0000-7000-8000-00000000000a';
    private const AUTHOR_B = '00000000-0000-7000-8000-00000000000b';

    protected function setUp(): void
    {
        $this->sqlitePath = sys_get_temp_dir() . '/typedock-ownership-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->pdo = new \PDO('sqlite:' . $this->sqlitePath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $migrator = new Migrator($this->pdo, 'sqlite', TYPEDOCK_ROOT . '/migrations');
        $migrator->migrate();

        // posts.author_id has an FK to users — seed two users so we can
        // attempt to swap ownership between them.
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $insert = $this->pdo->prepare(
            'INSERT INTO users (id, email, password_hash, name, role, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([self::AUTHOR_A, 'a@example.com', 'x', 'A', 'author', $now, $now]);
        $insert->execute([self::AUTHOR_B, 'b@example.com', 'x', 'B', 'author', $now, $now]);

        $this->service = new PostService($this->pdo);
    }

    protected function tearDown(): void
    {
        if (is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
    }

    public function test_update_refuses_to_change_author_id(): void
    {
        $post = $this->service->create([
            'title'     => 'Original',
            'author_id' => self::AUTHOR_A,
            'post_type' => 'post',
            'status'    => 'draft',
            'locale'    => 'en',
        ]);

        // Adversarial payload: caller tries to reassign post to author-B.
        $this->service->update($post['id'], [
            'title'     => 'Edited',
            'author_id' => self::AUTHOR_B,
        ]);

        $reloaded = $this->service->find($post['id']);
        self::assertSame('Edited',     $reloaded['title']);
        self::assertSame(self::AUTHOR_A, $reloaded['author_id'], 'author_id must be immutable on update.');
    }

    public function test_update_refuses_to_flip_post_type(): void
    {
        $post = $this->service->create([
            'title'     => 'A page',
            'author_id' => self::AUTHOR_A,
            'post_type' => 'page',
            'status'    => 'draft',
            'locale'    => 'en',
        ]);

        // Pages have stricter delete rules than posts (only editor/admin can
        // delete) — flipping post_type would let a contributor smuggle a
        // post under page-only routing rules.
        $this->service->update($post['id'], ['post_type' => 'post']);

        $reloaded = $this->service->find($post['id']);
        self::assertSame('page', $reloaded['post_type'], 'post_type must be immutable on update.');
    }

    public function test_update_refuses_to_change_locale(): void
    {
        $post = $this->service->create([
            'title'     => 'Hello',
            'author_id' => self::AUTHOR_A,
            'post_type' => 'post',
            'status'    => 'draft',
            'locale'    => 'en',
        ]);

        $this->service->update($post['id'], ['locale' => 'ja']);

        $reloaded = $this->service->find($post['id']);
        self::assertSame('en', $reloaded['locale'], 'locale must be immutable on update.');
    }

    public function test_update_still_persists_legitimate_fields(): void
    {
        // Sanity check: the lock-down didn't accidentally also block titles
        // / bodies / status changes — those are exactly what update() is for.
        $post = $this->service->create([
            'title'     => 'Draft',
            'author_id' => self::AUTHOR_A,
            'post_type' => 'post',
            'status'    => 'draft',
            'locale'    => 'en',
        ]);

        $this->service->update($post['id'], [
            'title'  => 'Final title',
            'status' => 'published',
        ]);

        $reloaded = $this->service->find($post['id']);
        self::assertSame('Final title', $reloaded['title']);
        self::assertSame('published',   $reloaded['status']);
    }
}
