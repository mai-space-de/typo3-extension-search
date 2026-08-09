<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Dto;

use Maispace\MaiSearch\Facet\FacetInterface;

/**
 * Paginated search result set with facet counts for filter UI (SPEC-v2.md Phase 6).
 */
readonly class SearchResultPage
{
    /**
     * @param list<SearchResult> $results
     * @param list<FacetInterface> $facets
     */
    public function __construct(
        public array $results,
        public int $total,
        public array $facets = [],
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

    public function getFacet(string $name): ?FacetInterface
    {
        foreach ($this->facets as $facet) {
            if ($facet->getName() === $name) {
                return $facet;
            }
        }

        return null;
    }
}
