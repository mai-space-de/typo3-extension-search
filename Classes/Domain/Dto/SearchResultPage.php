<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Dto;

/**
 * Paginated search result set with type facet counts for filter UI.
 */
readonly class SearchResultPage
{
    /**
     * @param list<SearchResult> $results
     * @param array<string, int> $types type => document count for current query (without type filter)
     */
    public function __construct(
        public array $results,
        public int $total,
        public array $types = [],
        public int $page = 1,
        public int $perPage = 20,
    ) {}

    public function getTotalPages(): int
    {
        if ($this->perPage < 1) {
            return 0;
        }

        return (int) ceil($this->total / $this->perPage);
    }
}
