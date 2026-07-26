<?php
declare(strict_types=1);

namespace TypeDock\Import;

/**
 * What a plugin implements to teach TypeDock a new export format.
 *
 * Everything CMS-aware — the database, resumption, deduplication, slugs,
 * authors — lives in Core. An importer only has to read a file and yield
 * documents, which is why adding "note" or "Ghost" later is one class rather
 * than a second importer subsystem.
 */
interface ImporterInterface
{
    /** Stable machine key, e.g. 'wordpress'. Stored in `posts.external_source`. */
    public function key(): string;

    /** Human label for the importer picker, e.g. 'WordPress (WXR)'. */
    public function label(): string;

    /**
     * File extensions this importer can read, without the dot.
     *
     * @return array<int, string>
     */
    public function accepts(): array;

    /**
     * Read the file and report what is in it. Must not write anything.
     */
    public function scan(string $file): ImportScan;

    /**
     * Stream the file's documents in file order.
     *
     * `$skip` is how many documents the caller has already processed: an
     * import that ran out of time resumes by asking for the same file again
     * with a larger skip, so implementations must count and skip cheaply
     * rather than materialising everything.
     *
     * @return \Generator<int, ImportDocument>
     */
    public function documents(string $file, int $skip = 0): \Generator;
}
