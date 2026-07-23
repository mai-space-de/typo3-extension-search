<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Solr;

/**
 * Parsed Solr select response.
 */
final class SearchResponse
{
    /**
     * @param list<array<string, mixed>> $documents
     * @param array<string, array<string, int>> $facetCounts facet key => (value => count)
     */
    public function __construct(
        private readonly array $documents,
        private readonly int $numFound,
        private readonly array $facetCounts = [],
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function getNumFound(): int
    {
        return $this->numFound;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getFacetCounts(): array
    {
        return $this->facetCounts;
    }

    /**
     * @return array<string, int>
     */
    public function getFacetCountsFor(string $key): array
    {
        return $this->facetCounts[$key] ?? [];
    }
}
