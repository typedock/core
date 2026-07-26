<?php
declare(strict_types=1);

namespace TypeDock\Import;

use TypeDock\Content\CategoryService;
use TypeDock\Content\PostService;
use TypeDock\Content\SlugValidator;
use TypeDock\Content\TagService;
use TypeDock\Content\TermSlugger;

/**
 * Turns an ImportDocument into a row in `posts`.
 *
 * Re-running the same file must not duplicate anything, so every write is an
 * upsert keyed on (external_source, external_id). That unique index is also
 * the old-id → new-id map: looking a reference up is a SELECT rather than a
 * few tens of thousands of entries held in memory on a shared host.
 */
final class ImportWriter
{
    private readonly SlugValidator $slugValidator;
    private readonly PostService $posts;
    private readonly CategoryService $categories;
    private readonly TagService $tags;

    /** @var array<string, ?string> Author email => user id, memoised per run. */
    private array $authorCache = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ImportOptions $options,
    ) {
        $this->slugValidator = new SlugValidator();
        $this->posts         = new PostService($this->pdo, $this->slugValidator);
        $this->categories    = new CategoryService($this->pdo);
        $this->tags          = new TagService($this->pdo);
    }

    /**
     * @return array{action:'created'|'updated', post_id:string}
     */
    public function write(ImportDocument $doc, string $importerKey, string $batchId): array
    {
        $existingId = $this->findExisting($importerKey, $doc->externalId);

        $data = [
            'title'        => $doc->title !== '' ? $doc->title : '(untitled)',
            'slug'         => $this->resolveSlug($doc, $existingId),
            'body'         => ['type' => 'doc', 'content' => $doc->blocks],
            'excerpt'      => $doc->excerpt,
            'post_type'    => $doc->type === PostService::TYPE_PAGE ? PostService::TYPE_PAGE : PostService::TYPE_POST,
            'status'       => $this->options->asDraft ? PostService::STATUS_DRAFT : $doc->status,
            'author_id'    => $this->resolveAuthor($doc),
            'locale'       => $this->options->locale,
            'published_at' => $doc->publishedAt,
            'scheduled_at' => $doc->scheduledAt,
            'parent_id'    => $this->resolveParent($importerKey, $doc->parentExternalId),
            'category_ids' => $this->resolveCategories($doc->categories),
            'tag_ids'      => $this->tags->findOrCreateByNames($doc->tags, $this->options->locale),
        ];

        if ($existingId !== null) {
            // No revision: an import is not an edit, and stacking one version
            // per post per re-run would bury the real history.
            $this->posts->update($existingId, $data, createRevision: false);
            $postId = $existingId;
            $action = 'updated';
        } else {
            $postId = (string) $this->posts->create($data)['id'];
            $action = 'created';
        }

        $this->stampProvenance($postId, $importerKey, $doc, $batchId);

        return ['action' => $action, 'post_id' => $postId];
    }

    /**
     * Fill in parent links that pointed at a document which had not been
     * imported yet. Runs once, after the last document has landed.
     *
     * Writes `posts.parent_id` only — never the body — so the "content is
     * written exactly once" property survives.
     *
     * @return int Number of parent links resolved.
     */
    public function resolvePendingParents(string $importerKey, string $batchId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, external_parent_id FROM posts
              WHERE import_batch_id = ? AND external_parent_id IS NOT NULL AND parent_id IS NULL'
        );
        $stmt->execute([$batchId]);
        $pending = $stmt->fetchAll();

        $update  = $this->pdo->prepare('UPDATE posts SET parent_id = ? WHERE id = ?');
        $resolved = 0;

        foreach ($pending as $row) {
            $parentId = $this->findExisting($importerKey, (string) $row['external_parent_id']);
            if ($parentId === null || $parentId === (string) $row['id']) {
                continue;
            }
            $update->execute([$parentId, $row['id']]);
            $resolved++;
        }

        return $resolved;
    }

    private function findExisting(string $importerKey, string $externalId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM posts WHERE external_source = ? AND external_id = ? LIMIT 1'
        );
        $stmt->execute([$importerKey, $externalId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (string) $id;
    }

    private function resolveParent(string $importerKey, ?string $parentExternalId): ?string
    {
        if ($parentExternalId === null || $parentExternalId === '') {
            return null;
        }

        return $this->findExisting($importerKey, $parentExternalId);
    }

    /**
     * Bookkeeping columns PostService does not know about. Kept as one narrow
     * statement here rather than widening the editorial service's contract.
     */
    private function stampProvenance(string $postId, string $importerKey, ImportDocument $doc, string $batchId): void
    {
        $this->pdo->prepare(
            'UPDATE posts SET external_source = ?, external_id = ?, external_parent_id = ?, import_batch_id = ?
              WHERE id = ?'
        )->execute([
            $importerKey,
            $doc->externalId,
            $doc->parentExternalId,
            $batchId,
            $postId,
        ]);
    }

    /**
     * A source slug is not necessarily a legal TypeDock slug: WordPress allows
     * percent-encoded non-ASCII permalinks, one-character names and words this
     * CMS reserves for routing. Fall back through title, then the source id,
     * so the result is still recognisable rather than a timestamp.
     */
    private function resolveSlug(ImportDocument $doc, ?string $excludeId): string
    {
        $candidates = [
            $doc->slug,
            $doc->title,
            'post-' . preg_replace('/\D+/', '', $doc->externalId),
        ];

        foreach ($candidates as $candidate) {
            $base = $this->asciiSlug((string) $candidate);
            if ($base !== '') {
                return $this->slugValidator->generateUnique($base, $this->pdo, $excludeId);
            }
        }

        return $this->slugValidator->generateUnique($doc->externalId, $this->pdo, $excludeId);
    }

    private function asciiSlug(string $value): string
    {
        $value = strtolower(rawurldecode(trim($value)));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    private function resolveAuthor(ImportDocument $doc): ?string
    {
        $email = strtolower(trim((string) $doc->authorEmail));
        if ($email === '') {
            return $this->options->defaultAuthorId;
        }

        if (!array_key_exists($email, $this->authorCache)) {
            $stmt = $this->pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $id = $stmt->fetchColumn();
            $this->authorCache[$email] = $id === false ? null : (string) $id;
        }

        return $this->authorCache[$email] ?? $this->options->defaultAuthorId;
    }

    /**
     * Attach the post's categories, creating any that are missing.
     *
     * Ancestors are created but *not* attached: being filed under "Releases"
     * does not mean the post is also filed under "News", but "Releases" cannot
     * exist without its parent.
     *
     * @param array<int, array{slug:string,name:string,ancestors?:array<int,array{slug:string,name:string}>}> $categories
     * @return array<int, string>
     */
    private function resolveCategories(array $categories): array
    {
        $ids = [];

        foreach ($categories as $category) {
            $parentId = null;
            foreach ($category['ancestors'] ?? [] as $ancestor) {
                $parentId = $this->ensureCategory(
                    (string) ($ancestor['slug'] ?? ''),
                    (string) ($ancestor['name'] ?? ''),
                    $parentId
                ) ?? $parentId;
            }

            $id = $this->ensureCategory(
                (string) ($category['slug'] ?? ''),
                (string) ($category['name'] ?? ''),
                $parentId
            );
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Find a category by slug, or create it. Never reparents an existing one —
     * the site owner's own arrangement outranks the export's.
     */
    private function ensureCategory(string $slug, string $name, ?string $parentId): ?string
    {
        $slug = TermSlugger::fromName($slug !== '' ? $slug : $name, 'category');
        if ($slug === '') {
            return null;
        }

        $existing = $this->categories->findBySlug($slug, $this->options->locale);
        if ($existing !== null) {
            return (string) $existing['id'];
        }

        $created = $this->categories->create([
            'name'      => $name !== '' ? $name : $slug,
            'slug'      => $slug,
            'parent_id' => $parentId,
            'locale'    => $this->options->locale,
        ]);

        return (string) $created['id'];
    }
}
