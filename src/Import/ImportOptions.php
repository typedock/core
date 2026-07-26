<?php
declare(strict_types=1);

namespace TypeDock\Import;

final class ImportOptions
{
    public function __construct(
        /** Import everything as a draft rather than honouring the source status. */
        public readonly bool $asDraft = false,
        /** Author used when the source author has no matching TypeDock account. */
        public readonly ?string $defaultAuthorId = null,
        public readonly string $locale = 'en',
        /**
         * Copy images into the media library. Turning this off leaves them
         * pointing at the source site — a reasonable choice when migrating
         * away from a host that will stay online, and the only choice when
         * the source is behind a login.
         */
        public readonly bool $fetchMedia = true,
        /**
         * Rewrite absolute links that point back at the source site. Needs
         * $sourceSiteUrl to know which links those are.
         */
        public readonly bool $rewriteLinks = true,
        public readonly ?string $sourceSiteUrl = null,
        /**
         * Whether the person who started the import may publish raw HTML.
         * When false, content that could not be converted into blocks is
         * dropped instead of being kept in a `custom_html` block — the dry
         * run says so up front rather than letting it vanish quietly.
         */
        public readonly bool $allowRawHtml = true,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['as_draft'] ?? false),
            isset($data['default_author_id']) ? (string) $data['default_author_id'] : null,
            (string) ($data['locale'] ?? typedock_default_locale()),
            (bool) ($data['fetch_media'] ?? true),
            (bool) ($data['rewrite_links'] ?? true),
            isset($data['source_site_url']) ? (string) $data['source_site_url'] : null,
            (bool) ($data['allow_raw_html'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'as_draft'          => $this->asDraft,
            'default_author_id' => $this->defaultAuthorId,
            'locale'            => $this->locale,
            'fetch_media'       => $this->fetchMedia,
            'rewrite_links'     => $this->rewriteLinks,
            'source_site_url'   => $this->sourceSiteUrl,
            'allow_raw_html'    => $this->allowRawHtml,
        ];
    }
}
