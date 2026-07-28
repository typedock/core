<?php
declare(strict_types=1);

namespace TypeDock\Import;

use TypeDock\Content\CategoryService;
use TypeDock\Content\PostService;
use TypeDock\Content\SlugValidator;
use TypeDock\Content\TagService;
use TypeDock\Content\TermSlugger;
use TypeDock\Core\Queue\JobQueue;
use TypeDock\Media\MediaService;

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

    /** @var array<string, true> Media ids already queued for download this run. */
    private array $queuedMedia = [];

    /** Blocks dropped because the importing user may not publish raw HTML. */
    private int $droppedRawHtml = 0;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ImportOptions $options,
        private readonly ?MediaService $media = null,
        private readonly ?JobQueue $queue = null,
    ) {
        $this->slugValidator = new SlugValidator();
        $this->posts         = new PostService($this->pdo, $this->slugValidator);
        $this->categories    = new CategoryService($this->pdo);
        $this->tags          = new TagService($this->pdo);
    }

    public const TYPE_ATTACHMENT = 'attachment';

    /**
     * @return array{action:'created'|'updated'|'attachment', post_id:string}
     */
    public function write(ImportDocument $doc, string $importerKey, string $batchId): array
    {
        if ($doc->type === self::TYPE_ATTACHMENT) {
            $this->registerAttachment($doc, $importerKey, $batchId);

            return ['action' => 'attachment', 'post_id' => ''];
        }

        $existingId = $this->findExisting($importerKey, $doc->externalId);
        $blocks     = $this->applyRawHtmlPolicy($doc->blocks);

        $data = [
            'title'        => $doc->title !== '' ? $doc->title : '(untitled)',
            'slug'         => $this->resolveSlug($doc, $existingId),
            'body'         => ['type' => 'doc', 'content' => $this->attachMedia($blocks, $batchId)],
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

    /** How many raw-HTML blocks this run refused to write. */
    public function droppedRawHtml(): int
    {
        return $this->droppedRawHtml;
    }

    /**
     * Record an asset so that references to it resolve, whether they arrived
     * before it or after. No download is queued here: an export lists the
     * whole media library, and fetching thousands of images nothing points at
     * is not what someone asked for. The resolve pass queues the ones that
     * turn out to be used and drops the rest.
     */
    private function registerAttachment(ImportDocument $doc, string $importerKey, string $batchId): void
    {
        if ($this->media === null || !$this->options->fetchMedia || $doc->sourceUrl === '') {
            return;
        }

        $this->media->registerExternal($importerKey, $doc->externalId, $doc->sourceUrl, $batchId);
    }

    /**
     * Enforce the `content:unfiltered_html` capability on imported content.
     *
     * The importer's promise is that nothing is lost silently — but a
     * `custom_html` block written on behalf of someone who is not allowed to
     * publish raw HTML would be an end-run around that permission. So the
     * blocks are dropped and *counted*, and the dry run warns before the
     * import starts rather than after.
     *
     * @param  array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function applyRawHtmlPolicy(array $blocks): array
    {
        if ($this->options->allowRawHtml) {
            return $blocks;
        }

        $kept = [];
        foreach ($blocks as $block) {
            if (is_array($block)
                && ($block['type'] ?? '') === 'componentBlock'
                && ($block['attrs']['component'] ?? '') === 'custom_html'
            ) {
                $this->droppedRawHtml++;
                continue;
            }
            if (is_array($block) && isset($block['content']) && is_array($block['content'])) {
                $block['content'] = $this->applyRawHtmlPolicy($block['content']);
            }
            $kept[] = $block;
        }

        return $kept;
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

    /**
     * Attach featured images now that every document has been read.
     *
     * Runs at the end for the same reason parents do: the asset a post points
     * at may be listed after it. Unlike the classic implementation this needs
     * no in-memory map — the reference is on the post row and the asset has a
     * key of its own — so it works identically whether the import ran in one
     * request or forty.
     *
     * References with no matching asset are counted rather than ignored. A
     * broken body image is obvious; a missing featured image is an empty
     * thumbnail that nobody notices for months.
     *
     * @return array{resolved:int, unresolved:int, non_image:int}
     */
    public function resolvePendingFeatured(string $importerKey, string $batchId): array
    {
        if ($this->media === null || !$this->options->fetchMedia) {
            return ['resolved' => 0, 'unresolved' => 0, 'non_image' => 0];
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, post_type, external_featured_id FROM posts
              WHERE import_batch_id = ? AND external_featured_id IS NOT NULL'
        );
        $stmt->execute([$batchId]);

        $seo        = new \TypeDock\Seo\SeoService($this->pdo);
        $referenced = [];
        $resolved   = 0;
        $unresolved = 0;
        $nonImage   = 0;

        foreach ($stmt->fetchAll() as $row) {
            $asset = $this->media->findByExternalId($importerKey, (string) $row['external_featured_id']);
            if ($asset === null) {
                $unresolved++;
                continue;
            }

            // The asset still counts as referenced — it is kept and fetched —
            // even when it turns out not to be usable as a thumbnail.
            $referenced[] = (string) $asset['id'];
            $this->queueDownload($asset, $batchId);

            // WordPress lets a PDF be the featured "image" because it swaps in
            // a preview it generated at display time. Wiring the PDF itself
            // into og_image would produce a thumbnail no browser can draw, and
            // an og:image tag no crawler can use, so leave it unset and count
            // it. The file stays in the library either way.
            if (!str_starts_with((string) $asset['mime_type'], 'image/')) {
                $nonImage++;
                continue;
            }

            $resolved++;

            // Merge rather than replace: a re-import must not blank a meta
            // description someone wrote by hand.
            $type     = (string) $row['post_type'];
            $existing = $seo->findByTarget($type, (string) $row['id']) ?? [];
            $seo->upsert($type, (string) $row['id'], array_merge($existing, [
                'og_image_id' => (string) $asset['id'],
            ]));
        }

        $this->discardUnusedAttachments($batchId, $referenced);

        return ['resolved' => $resolved, 'unresolved' => $unresolved, 'non_image' => $nonImage];
    }

    /**
     * Drop asset rows nothing ended up pointing at.
     *
     * They are identifiable because only assets carry an `external_id` —
     * images found in a post body are keyed by URL alone. Leaving them would
     * fill the media library with entries that have no file and never will.
     *
     * @param array<int, string> $keepIds
     */
    private function discardUnusedAttachments(string $batchId, array $keepIds): void
    {
        $sql    = "DELETE FROM media
                    WHERE import_batch_id = ? AND status = 'pending' AND external_id IS NOT NULL";
        $params = [$batchId];

        if ($keepIds !== []) {
            $sql .= ' AND id NOT IN (' . implode(', ', array_fill(0, count($keepIds), '?')) . ')';
            $params = array_merge($params, $keepIds);
        }

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Point every remote image at the media library *before* the body is
     * written for the first time.
     *
     * `reserve()` hands back the row and therefore the final URL without
     * touching the network, so the body is written once and never revisited —
     * no bulk rewrite pass over every imported post once the downloads
     * finish, and no revision churn from one.
     *
     * @param  array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function attachMedia(array $blocks, string $batchId): array
    {
        if ($this->media === null || !$this->options->fetchMedia) {
            return $blocks;
        }

        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }

            if (($block['type'] ?? '') === 'image') {
                $src = (string) ($block['attrs']['src'] ?? '');
                if (preg_match('#^https?://#i', $src) === 1) {
                    $reserved = $this->media->reserve(self::originalSizeUrl($src), $batchId);
                    if ($reserved !== null) {
                        $blocks[$index]['attrs']['src']     = (string) $reserved['url'];
                        $blocks[$index]['attrs']['mediaId'] = (string) $reserved['id'];
                        $this->queueDownload($reserved, $batchId);
                    }
                }
            }

            if (isset($block['content']) && is_array($block['content'])) {
                $blocks[$index]['content'] = $this->attachMedia($block['content'], $batchId);
            }
        }

        return $blocks;
    }

    /** @param array<string, mixed> $media */
    private function queueDownload(array $media, string $batchId): void
    {
        $id = (string) $media['id'];
        if ($this->queue === null || (string) $media['status'] !== 'pending' || isset($this->queuedMedia[$id])) {
            return;
        }

        $this->queuedMedia[$id] = true;
        $this->queue->push('import.media', ['media_id' => $id], $batchId);
    }

    /**
     * WordPress rewrites `photo.jpg` into `photo-300x200.jpg` for every
     * registered size; the original is what we want. Both dimensions must be
     * two digits or more before the suffix is treated as generated — files
     * genuinely named `chart-1x2.png` exist, and mangling them would 404.
     */
    private static function originalSizeUrl(string $url): string
    {
        return preg_replace('/-\d{2,}x\d{2,}(\.[a-z0-9]+)$/i', '$1', $url) ?? $url;
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
            'UPDATE posts SET external_source = ?, external_id = ?, external_parent_id = ?,
                              external_featured_id = ?, external_url = ?, import_batch_id = ?
              WHERE id = ?'
        )->execute([
            $importerKey,
            $doc->externalId,
            $doc->parentExternalId,
            $doc->featuredExternalId,
            $doc->sourceUrl !== '' ? $doc->sourceUrl : null,
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
        // Only the source slug may carry a path. A page's slug *is* its URL
        // path — the front controller looks pages up by the whole request path
        // and SlugValidator allows slashes — so `showcase/customer-a` has to
        // survive the trip. Posts get no such freedom: they live under one
        // archive segment, and the router's `@slug` placeholder stops at a
        // slash. A title that happens to contain a slash is not a hierarchy,
        // so the fallbacks are flattened either way.
        $candidates = [
            $this->normaliseSlug($doc->slug, keepPath: $doc->type === PostService::TYPE_PAGE),
            $this->normaliseSlug($doc->title),
            $this->normaliseSlug('post-' . preg_replace('/\D+/', '', $doc->externalId)),
        ];

        foreach ($candidates as $base) {
            if ($base !== '') {
                return $this->slugValidator->adoptUnique($base, $this->pdo, $excludeId);
            }
        }

        return $this->slugValidator->generateUnique($doc->externalId, $this->pdo, $excludeId);
    }

    /**
     * Normalise a source slug for storage.
     *
     * WordPress percent-encodes non-ASCII permalinks, so the value is decoded
     * first and kept — `%e3%81%8a%e7%9f%a5%e3%82%89%e3%81%9b` is `お知らせ`,
     * and flattening it to ASCII used to leave the post on a timestamp slug,
     * breaking every inbound link a Japanese site had.
     *
     * `$keepPath` keeps `/` as a separator instead of flattening it to `-`,
     * collapsing the punctuation around it so the result has no leading,
     * trailing or doubled separator — the shape SlugValidator::validate()
     * accepts.
     */
    private function normaliseSlug(string $value, bool $keepPath = false): string
    {
        $value = mb_strtolower(rawurldecode(trim($value)), 'UTF-8');
        // Invisible letters are stripped before the class below turns
        // everything else into a separator — an export is attacker-supplied,
        // and a slug made of Hangul fillers renders as nothing at all.
        $value = preg_replace('/[' . SlugValidator::INVISIBLE_LETTERS . ']+/u', '', $value) ?? '';
        $value = preg_replace(
            $keepPath
                ? '#[^' . SlugValidator::CHAR_CLASS . '/]+#u'
                : '/[^' . SlugValidator::CHAR_CLASS . ']+/u',
            '-',
            $value
        ) ?? '';

        if ($keepPath) {
            $value = preg_replace('#-*/+-*#', '/', $value) ?? $value;

            return trim($value, '-/');
        }

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
