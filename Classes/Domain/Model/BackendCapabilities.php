<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

/**
 * Describes what a search backend can and cannot do, so callers (SearchService,
 * the frontend plugin, the backend module) can adapt instead of assuming
 * Solr-level feature parity. indexed_search is a degraded-capability backend by
 * design — see SPEC-v2.md decision #3 — not a bug to work around.
 */
readonly class BackendCapabilities
{
    public function __construct(
        public bool $supportsRealtimeIndexing,
        public bool $supportsFacets,
        public bool $supportsVectorSearch,
        public bool $hasComparableRelevanceScore,
    ) {}
}
