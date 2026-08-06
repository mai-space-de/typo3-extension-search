<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Service\BackendRegistry;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Backend-agnostic search entry point (SPEC-v2.md Phase 1). Builds a
 * SearchRequest and delegates to whichever SearchBackendInterface
 * implementation BackendRegistry resolves as active — Solr, and from Phase 2
 * onward Meilisearch or indexed_search too. Callers (SearchController) don't
 * need to change when the active backend changes.
 */
class SearchService implements SingletonInterface
{
    public function __construct(
        private readonly BackendRegistry $backendRegistry,
    ) {}

    /**
     * Execute a search query against the active backend.
     *
     * When $ragEnabled is true, the active backend may enhance the query with
     * a hybrid vector re-rank if it supports vector search (see
     * BackendCapabilities::$supportsVectorSearch) — capability details are the
     * backend's concern, not the caller's.
     *
     * @param string $query The user-entered search query
     * @param int $limit Maximum number of results to return
     * @param int $offset Zero-based offset for pagination
     * @param SiteLanguage|null $language Current site language for backend/core selection
     * @param bool $ragEnabled Whether to enable hybrid vector+text search
     * @param string|null $type Optional type filter (news, page, events, …)
     */
    public function search(
        string $query,
        int $limit = 20,
        int $offset = 0,
        ?SiteLanguage $language = null,
        bool $ragEnabled = false,
        ?string $type = null,
    ): SearchResultPage {
        $request = new SearchRequest(
            query: $query,
            limit: $limit,
            offset: $offset,
            language: $language,
            type: $type,
            ragEnabled: $ragEnabled,
        );

        return $this->backendRegistry->getActive()->search($request);
    }
}
