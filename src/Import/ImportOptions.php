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
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (bool) ($data['as_draft'] ?? false),
            isset($data['default_author_id']) ? (string) $data['default_author_id'] : null,
            (string) ($data['locale'] ?? typedock_default_locale()),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'as_draft'          => $this->asDraft,
            'default_author_id' => $this->defaultAuthorId,
            'locale'            => $this->locale,
        ];
    }
}
