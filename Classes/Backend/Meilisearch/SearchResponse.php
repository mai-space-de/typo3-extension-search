<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

/**
 * Parsed Meilisearch search response. Extends mai_meilisearch's original DTO
 * (hits/estimatedTotalHits/processingTimeMs) with $facetDistribution — needed
 * so MeilisearchBackend can populate SearchResultPage::$types the same way
 * SolrBackend does from Solr facet counts.
 */
final readonly class SearchResponse
{
    /**
     * @param array<int, array<string, mixed>> $hits
     * @param array<string, array<string, int>> $facetDistribution facet field => (value => count)
     */
    public function __construct(
        public array $hits,
        public int $estimatedTotalHits,
        public int $processingTimeMs,
        public array $facetDistribution = [],
    ) {}
}
