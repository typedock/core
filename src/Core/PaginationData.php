<?php
declare(strict_types=1);

namespace TypeDock\Core;

final class PaginationData
{
    public readonly int $totalPages;

    public function __construct(
        public readonly int $current,
        int $totalPages,
        public readonly int $perPage,
        public readonly int $totalItems,
        private readonly string $baseUrl,
        private readonly bool $useQueryString = false,
    ) {
        $this->totalPages = max(1, $totalPages);
    }

    public function hasPrev(): bool
    {
        return $this->current > 1;
    }

    public function hasNext(): bool
    {
        return $this->current < $this->totalPages;
    }

    public function url(int $page): string
    {
        if ($this->useQueryString) {
            $sep = str_contains($this->baseUrl, '?') ? '&' : '?';
            return $page === 1 ? $this->baseUrl : $this->baseUrl . $sep . 'page=' . $page;
        }
        $base = rtrim($this->baseUrl, '/');
        return $page === 1 ? ($base === '' ? '/' : $base) : $base . '/page/' . $page;
    }

    /**
     * @return array<int>
     */
    public function range(int $window = 2): array
    {
        $start = max(1, $this->current - $window);
        $end   = min($this->totalPages, $this->current + $window);
        return range($start, $end);
    }
}
