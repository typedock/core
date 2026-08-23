<?php
declare(strict_types=1);

namespace TypeDock\Plugin\ImportWordPress;

use TypeDock\Import\ImportDocument;
use TypeDock\Import\ImporterInterface;
use TypeDock\Import\ImportScan;

/**
 * Reads a WordPress eXtended RSS (WXR) export.
 *
 * Streamed with XMLReader, one `<item>` at a time: a WXR file for a decade-old
 * blog runs to hundreds of megabytes, and loading that into a DOM would blow
 * a shared host's 128MB memory limit before the first post landed.
 *
 * Security: the file is attacker-controlled by definition — anyone can hand
 * their "WordPress export" to a site owner. See guardAgainstDoctype() for what
 * that costs us.
 */
final class WxrImporter implements ImporterInterface
{
    private const WP_NS = 'http://wordpress.org/export/1.2/';

    /** WordPress statuses we deliberately skip rather than import. */
    private const SKIP_STATUSES = ['trash', 'auto-draft'];

    public function key(): string
    {
        return 'wordpress';
    }

    public function label(): string
    {
        return 'WordPress (WXR)';
    }

    /** @return array<int, string> */
    public function accepts(): array
    {
        return ['xml', 'gz'];
    }

    public function scan(string $file): ImportScan
    {
        $counts   = ['post' => 0, 'page' => 0, 'skipped' => 0, 'attachment' => 0];
        $warnings = [];
        $unmapped = 0;
        $context  = new WxrContext();

        foreach ($this->items($file, $context) as $item) {
            $postType = $this->wpValue($item, 'post_type');
            $status   = $this->wpValue($item, 'status');

            if ($postType === 'attachment') {
                $counts['attachment']++;
                continue;
            }
            if (!in_array($postType, ['post', 'page'], true) || in_array($status, self::SKIP_STATUSES, true)) {
                $counts['skipped']++;
                if (!in_array($postType, ['post', 'page', 'attachment', 'nav_menu_item'], true)) {
                    $warnings[] = "Custom post type \"{$postType}\" is not imported.";
                }
                continue;
            }

            $counts[$postType]++;

            $html      = $this->contentOf($item);
            $converted = (new HtmlToBlocks())->convert($html);
            $unmapped += $converted['unmapped'];
            foreach ($converted['warnings'] as $warning) {
                $warnings[] = $warning;
            }
            $context->externalLinkCount += $this->countExternalLinks($html, $context);
        }

        if ($context->externalLinkCount > 0) {
            $warnings[] = sprintf(
                '%d link(s) still point at %s and are left as absolute URLs.',
                $context->externalLinkCount,
                (string) $context->baseUrl
            );
        }

        return new ImportScan(
            counts: $counts,
            warnings: array_values(array_unique($warnings)),
            authors: array_values($context->authors),
            unmappedNodes: $unmapped,
            sourceSiteUrl: $context->baseUrl,
        );
    }

    /**
     * @return \Generator<int, ImportDocument>
     */
    public function documents(string $file, int $skip = 0): \Generator
    {
        $context = new WxrContext();
        $index   = 0;

        foreach ($this->items($file, $context) as $item) {
            // `$skip` counts documents the caller has processed, not `<item>`
            // elements — a file full of trashed posts and menu entries would
            // otherwise resume at the wrong place. Importability is decided
            // from a few cheap fields so that skipped items never pay for HTML
            // conversion, which is the expensive part.
            if (!$this->isImportable($item)) {
                continue;
            }
            if ($index++ < $skip) {
                continue;
            }

            $document = $this->toDocument($item, $context);
            if ($document !== null) {
                yield $document;
            }
        }
    }

    /**
     * Whether toDocument() will produce something for this item. Must stay in
     * step with it: a disagreement makes resumption skip or repeat documents.
     */
    private function isImportable(\SimpleXMLElement $item): bool
    {
        $postType = $this->wpValue($item, 'post_type');

        if ($postType === 'attachment') {
            $url = $this->wpValue($item, 'attachment_url') ?: trim((string) $item->guid);

            return $url !== '' && preg_match('#^https?://#i', $url) === 1;
        }

        return in_array($postType, ['post', 'page'], true)
            && !in_array($this->wpValue($item, 'status'), self::SKIP_STATUSES, true);
    }

    /**
     * Every `<item>` in file order.
     *
     * @return \Generator<int, \SimpleXMLElement>
     */
    private function items(string $file, WxrContext $context): \Generator
    {
        $this->guardAgainstDoctype($file);

        $reader = new \XMLReader();
        $opened = @$reader->open($this->streamUri($file), 'UTF-8', LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($opened === false) {
            throw new \RuntimeException("Could not open export file: {$file}");
        }

        $previous = libxml_use_internal_errors(true);
        $dom      = new \DOMDocument();

        try {
            // Everything before the first <item> is channel metadata: the site
            // URL used to detect internal links, the author list that maps
            // logins to email addresses, and the full category tree with its
            // parents (which is why category hierarchy needs no deferred
            // resolution the way page parents do).
            $foundItem = false;
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'item') {
                    $foundItem = true;
                    break;
                }
                $this->readChannelMetadata($reader, $dom, $context);
            }

            if (!$foundItem) {
                return;
            }

            do {
                $node = $reader->expand($dom);
                if ($node === false) {
                    continue;
                }
                $item = simplexml_import_dom($node);
                if ($item instanceof \SimpleXMLElement) {
                    yield $item;
                }
            } while ($reader->next('item'));
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function readChannelMetadata(\XMLReader $reader, \DOMDocument $dom, WxrContext $context): void
    {
        if ($reader->namespaceURI !== self::WP_NS) {
            return;
        }

        switch ($reader->localName) {
            case 'base_blog_url':
            case 'base_site_url':
                // expand() rather than readString(): readString() advances the
                // reader, which would silently skip the next channel element.
                $node = $reader->expand($dom);
                $url  = $node === false ? '' : trim($node->textContent);
                if ($url !== '' && $context->baseUrl === null) {
                    $context->baseUrl = rtrim($url, '/');
                }
                break;

            case 'author':
                $node = $reader->expand($dom);
                if ($node === false) {
                    return;
                }
                $author = simplexml_import_dom($node);
                if (!$author instanceof \SimpleXMLElement) {
                    return;
                }
                $login = $this->wpValue($author, 'author_login');
                if ($login === '') {
                    return;
                }
                $context->authors[$login] = [
                    'email' => $this->wpValue($author, 'author_email') ?: null,
                    'name'  => $this->wpValue($author, 'author_display_name') ?: $login,
                ];
                break;

            case 'category':
                $node = $reader->expand($dom);
                if ($node === false) {
                    return;
                }
                $category = simplexml_import_dom($node);
                if (!$category instanceof \SimpleXMLElement) {
                    return;
                }
                $nicename = $this->wpValue($category, 'category_nicename');
                if ($nicename === '') {
                    return;
                }
                $context->categories[$nicename] = [
                    'name'   => $this->wpValue($category, 'cat_name') ?: $nicename,
                    'parent' => $this->wpValue($category, 'category_parent') ?: null,
                ];
                break;
        }
    }

    private function toDocument(\SimpleXMLElement $item, WxrContext $context): ?ImportDocument
    {
        $postType = $this->wpValue($item, 'post_type');
        $status   = $this->wpValue($item, 'status');

        if ($postType === 'attachment') {
            return $this->toAttachment($item, $context);
        }

        if (!in_array($postType, ['post', 'page'], true) || in_array($status, self::SKIP_STATUSES, true)) {
            return null;
        }

        $html      = $this->contentOf($item);
        $converted = (new HtmlToBlocks())->convert($html);
        $warnings  = $converted['warnings'];

        $externalLinks = $this->countExternalLinks($html, $context);
        $context->externalLinkCount += $externalLinks;
        if ($externalLinks > 0) {
            $warnings[] = sprintf('%d link(s) still point at the source site.', $externalLinks);
        }

        $author = $context->authors[(string) $this->firstValue($item, 'creator', 'http://purl.org/dc/elements/1.1/')] ?? null;

        $parent = $this->wpValue($item, 'post_parent');
        $parent = ($parent === '' || $parent === '0') ? null : $parent;

        [$mappedStatus, $publishedAt, $scheduledAt] = $this->mapStatus($status, $this->wpValue($item, 'post_date'));

        $thumbnailId = $this->metaValue($item, '_thumbnail_id');

        return new ImportDocument(
            externalId: $this->qualify($this->wpValue($item, 'post_id'), $context),
            type: $postType,
            title: trim((string) $item->title),
            slug: $this->wpValue($item, 'post_name'),
            status: $mappedStatus,
            blocks: $converted['blocks'],
            excerpt: $this->excerptOf($item),
            parentExternalId: $parent !== null ? $this->qualify($parent, $context) : null,
            featuredExternalId: $thumbnailId !== '' ? $this->qualify($thumbnailId, $context) : null,
            publishedAt: $publishedAt,
            scheduledAt: $scheduledAt,
            authorEmail: $author['email'] ?? null,
            authorName: $author['name'] ?? null,
            categories: $this->termsOf($item, 'category', $context),
            tags: array_column($this->termsOf($item, 'post_tag', $context), 'name'),
            sourceUrl: trim((string) $item->link),
            warnings: $warnings,
            unmappedNodes: $converted['unmapped'],
        );
    }

    /**
     * An attachment becomes a media row, not a post.
     *
     * `wp:attachment_url` is the right answer and `guid` is the fallback,
     * because exports produced by older or patched WordPress installs are
     * missing the former often enough to matter.
     */
    private function toAttachment(\SimpleXMLElement $item, WxrContext $context): ?ImportDocument
    {
        $url = $this->wpValue($item, 'attachment_url');
        if ($url === '') {
            $url = trim((string) $item->guid);
        }
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }

        $url = $this->previewUrl($url, $item) ?? $url;

        return new ImportDocument(
            externalId: $this->qualify($this->wpValue($item, 'post_id'), $context),
            type: 'attachment',
            title: trim((string) $item->title),
            slug: $this->wpValue($item, 'post_name'),
            status: 'draft',
            blocks: [],
            sourceUrl: $url,
        );
    }

    /**
     * The image WordPress would actually show for a non-image attachment.
     *
     * A PDF can be a post's featured image: WordPress renders the JPEG it
     * generated beside the file, recorded in `_wp_attachment_metadata` under
     * the `full` size. `wp:attachment_url` names the PDF, so importing that
     * faithfully produces a featured image no browser can draw. Prefer the
     * preview when the export carries one.
     *
     * Returns null for ordinary images — their metadata has no `full` entry
     * and the attachment URL is already the original — and for PDFs exported
     * without a preview, which ImportWriter then declines to make featured.
     */
    private function previewUrl(string $url, \SimpleXMLElement $item): ?string
    {
        if (self::isImagePath($url)) {
            return null;
        }

        $meta = $this->metaValue($item, '_wp_attachment_metadata');
        if ($meta === '') {
            return null;
        }

        // `allowed_classes: false` matters: an export is attacker-supplied by
        // definition, and this field is serialized PHP. Without it,
        // unserialize() would instantiate whatever class the file names.
        $data = @unserialize($meta, ['allowed_classes' => false]);
        $file = is_array($data) ? ($data['sizes']['full']['file'] ?? null) : null;
        if (!is_string($file) || $file === '' || !self::isImagePath($file)) {
            return null;
        }

        // The preview sits beside the source file and `file` is a bare name;
        // basename() keeps a crafted `../` from walking to another host path.
        $slash = strrpos($url, '/');

        return $slash === false ? null : substr($url, 0, $slash + 1) . basename($file);
    }

    private static function isImagePath(string $pathOrUrl): bool
    {
        $path = parse_url($pathOrUrl, PHP_URL_PATH);
        $ext  = pathinfo(is_string($path) && $path !== '' ? $path : $pathOrUrl, PATHINFO_EXTENSION);

        return in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    /**
     * Namespace a source id by the site it came from.
     *
     * WordPress post ids are only unique within one installation, so importing
     * two different sites would otherwise have post 5 from the second quietly
     * overwrite post 5 from the first.
     */
    private function qualify(string $id, WxrContext $context): string
    {
        if ($id === '') {
            return $id;
        }

        $host = $context->baseUrl !== null ? parse_url($context->baseUrl, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host . ':' . $id : $id;
    }

    private function metaValue(\SimpleXMLElement $item, string $key): string
    {
        foreach ($item->children(self::WP_NS)->postmeta as $meta) {
            $fields = $meta->children(self::WP_NS);
            if (trim((string) $fields->meta_key) === $key) {
                return trim((string) $fields->meta_value);
            }
        }

        return '';
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string} status, published_at, scheduled_at
     */
    private function mapStatus(string $wpStatus, string $postDate): array
    {
        $date = ($postDate === '' || str_starts_with($postDate, '0000')) ? null : $postDate;

        return match ($wpStatus) {
            'publish' => ['published', $date, null],
            'future'  => ['scheduled', null, $date],
            'pending' => ['review', null, null],
            // WordPress "private" has no TypeDock equivalent; draft is the
            // conservative reading — better unpublished than accidentally public.
            default   => ['draft', null, null],
        };
    }

    /**
     * @return array<int, array{slug:string, name:string, ancestors:array<int, array{slug:string, name:string}>}>
     */
    private function termsOf(\SimpleXMLElement $item, string $domain, WxrContext $context): array
    {
        $terms = [];
        foreach ($item->category as $category) {
            $attrs = $category->attributes();
            if ((string) ($attrs['domain'] ?? '') !== $domain) {
                continue;
            }
            $slug = (string) ($attrs['nicename'] ?? '');
            $name = trim((string) $category);
            if ($slug === '' && $name === '') {
                continue;
            }
            $terms[] = [
                'slug'      => $slug,
                'name'      => $name !== '' ? $name : $slug,
                'ancestors' => $context->ancestorsOf($slug),
            ];
        }

        return $terms;
    }

    /**
     * WordPress fabricates an excerpt at display time when the author did not
     * write one, and those end in "[…]". Importing them would turn a
     * generated preview into permanent content.
     */
    private function excerptOf(\SimpleXMLElement $item): ?string
    {
        $excerpt = trim((string) $this->firstValue($item, 'encoded', 'http://wordpress.org/export/1.2/excerpt/'));
        if ($excerpt === '' || preg_match('/\[(?:…|\.\.\.|&hellip;)\]\s*$/u', $excerpt) === 1) {
            return null;
        }

        return $excerpt;
    }

    private function contentOf(\SimpleXMLElement $item): string
    {
        return (string) $this->firstValue($item, 'encoded', 'http://purl.org/rss/1.0/modules/content/');
    }

    /**
     * Count absolute links back to the source site. They are reported, not
     * rewritten: the correct new path depends on whether the target became a
     * post or a page and on the slug the writer finally settled on, so
     * rewriting belongs in a resolve pass that runs after every document has
     * landed — not here, guessing.
     */
    private function countExternalLinks(string $html, WxrContext $context): int
    {
        if ($context->baseUrl === null || $context->baseUrl === '') {
            return 0;
        }

        $host = parse_url($context->baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return 0;
        }

        return preg_match_all(
            '#<a\s[^>]*href=["\']https?://' . preg_quote($host, '#') . '#i',
            $html
        ) ?: 0;
    }

    private function wpValue(\SimpleXMLElement $element, string $name): string
    {
        return trim((string) $this->firstValue($element, $name, self::WP_NS));
    }

    private function firstValue(\SimpleXMLElement $element, string $name, string $namespace): string
    {
        $children = $element->children($namespace);

        return isset($children->{$name}) ? (string) $children->{$name} : '';
    }

    /**
     * XMLReader can read straight out of a gzip stream, which matters because
     * hosts with a small upload limit are exactly the ones whose users gzip
     * the export.
     */
    private function streamUri(string $file): string
    {
        return str_ends_with(strtolower($file), '.gz')
            ? 'compress.zlib://' . $file
            : $file;
    }

    /**
     * Refuse any file carrying a DOCTYPE.
     *
     * PHP 8 already ignores external entities by default — the
     * `libxml_disable_entity_loader()` call every XXE guide recommends is
     * deprecated and does nothing here. What is still live is the internal
     * entity expansion bomb ("billion laughs"), which needs no network access
     * at all and is defined entirely inside a DOCTYPE. WXR has no legitimate
     * use for one, so rejecting the whole construct closes both doors without
     * needing to reason about expansion limits.
     */
    private function guardAgainstDoctype(string $file): void
    {
        $handle = @fopen($this->streamUri($file), 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Could not read export file: {$file}");
        }

        $head = (string) fread($handle, 65536);
        fclose($handle);

        if (stripos($head, '<!DOCTYPE') !== false) {
            throw new \RuntimeException(
                'This export declares a DOCTYPE. WordPress exports never do, and processing one '
                . 'would expose the site to XML entity-expansion attacks, so the file is refused.'
            );
        }
    }
}
