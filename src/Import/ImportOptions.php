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
        ];
    }
}
