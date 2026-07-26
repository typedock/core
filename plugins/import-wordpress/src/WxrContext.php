<?php
declare(strict_types=1);

namespace TypeDock\Plugin\ImportWordPress;

/**
 * Channel-level facts gathered before the first `<item>`.
 *
 * WXR puts the author list and the complete category tree in the header, so
 * both are fully known by the time any post is read. That is why category
 * hierarchy needs no deferred resolution, unlike page parents, which are only
 * discoverable from the items themselves.
 */
final class WxrContext
{
    public ?string $baseUrl = null;

    /** @var array<string, array{email:?string, name:string}> Keyed by author_login. */
    public array $authors = [];

    /** @var array<string, array{name:string, parent:?string}> Keyed by category nicename. */
    public array $categories = [];

    /**
     * Ancestors of a category, root first. Resolvable up front because the
     * header carries the whole tree.
     *
     * @return array<int, array{slug:string, name:string}>
     */
    public function ancestorsOf(string $nicename): array
    {
        $chain = [];
        $seen  = [];
        $current = $this->categories[$nicename]['parent'] ?? null;

        while (is_string($current) && $current !== '' && !isset($seen[$current])) {
            $seen[$current] = true;
            array_unshift($chain, [
                'slug' => $current,
                'name' => $this->categories[$current]['name'] ?? $current,
            ]);
            $current = $this->categories[$current]['parent'] ?? null;
        }

        return $chain;
    }

    /** Absolute links back to the source site, counted for the dry run. */
    public int $externalLinkCount = 0;
}
