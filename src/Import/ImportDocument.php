<?php
declare(strict_types=1);

namespace TypeDock\Import;

/**
 * One piece of content, as produced by an importer and consumed by
 * ImportWriter.
 *
 * Deliberately knows nothing about TypeDock's database: an importer can be
 * unit-tested against a fixture file without a PDO handle anywhere in sight.
 * The one place it is not CMS-agnostic is `$blocks`, which is Tiptap JSON —
 * inventing a second intermediate representation to translate into Tiptap
 * would be a layer that earns nothing.
 *
 * References to other documents (`$parentExternalId`) stay *unresolved*.
 * Export files are not topologically sorted, so the writer stores them as-is
 * and a resolve pass fills them in once every document has landed.
 */
final class ImportDocument
{
    /**
     * @param string                                          $externalId Stable id in the source system, e.g. "1234"
     * @param string                                          $type       'post' | 'page'
     * @param array<int, array<string, mixed>>                $blocks     Tiptap nodes
     * @param array<int, array{slug:string,name:string,ancestors?:array<int,array{slug:string,name:string}>}> $categories
     *                                                                       Ancestors run root-first so the writer can
     *                                                                       create a hierarchy in one pass.
     * @param array<int, string>                              $tags
     * @param array<int, string>                              $warnings   Human-readable, shown in the dry run
     * @param int                                             $unmappedNodes Elements that could not be converted
     *                                                                       and were kept as raw HTML (never silently
     *                                                                       dropped — see doc36 §9-1)
     */
    public function __construct(
        public readonly string $externalId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $status,
        public readonly array $blocks,
        public readonly ?string $excerpt = null,
        public readonly ?string $parentExternalId = null,
        public readonly ?string $publishedAt = null,
        public readonly ?string $scheduledAt = null,
        public readonly ?string $authorEmail = null,
        public readonly ?string $authorName = null,
        public readonly array $categories = [],
        public readonly array $tags = [],
        public readonly string $sourceUrl = '',
        public readonly array $warnings = [],
        public readonly int $unmappedNodes = 0,
    ) {
    }
}
