<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Model;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Backend-agnostic search request, extracted from SearchService::search()'s
 * parameter list (SPEC-v2.md Phase 0/1) so every SearchBackendInterface
 * implementation — Solr, Meilisearch, indexed_search — accepts the same shape.
 *
 * Backend-specific tuning (e.g. Solr's KNN topK/weight for hybrid re-ranking)
 * intentionally stays out of this DTO: no caller has ever overridden those
 * defaults, so they remain private constants on SolrBackend instead of leaking
 * a Solr concept into the generic contract.
 */
readonly class SearchRequest
{
    /**
     * @var array<string, string>
     */
    public array $filters;

    /**
     * @param ?string $type Convenience constructor arg for the one facet field
     *                      that exists today; mirrored into $filters so backends
     *                      can loop over filters generically (SPEC-v2.md Phase 6).
     */
    public function __construct(
        public string $query,
        public int $limit = 20,
        public int $offset = 0,
        public ?SiteLanguage $language = null,
        public ?string $type = null,
        public bool $ragEnabled = false,
    ) {
        $this->filters = $type !== null ? ['type' => $type] : [];
    }
}
