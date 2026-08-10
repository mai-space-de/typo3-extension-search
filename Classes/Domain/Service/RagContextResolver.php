<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Reranking\RerankerInterface;
use Maispace\MaiSearch\Service\BackendRegistry;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Resolves the best RAG context for a query by widening retrieval beyond the
 * paginated display list and reranking the wider set (SPEC-v2.md Phase 7
 * addendum). Deliberately independent of pagination — it always queries from
 * offset 0, since "which documents best answer this question" is unrelated
 * to "which page of results the visitor is currently viewing."
 *
 * Used only when the caller opts in (see SearchController's ragRerankEnabled
 * check) — callers that don't want the extra search + chat-completion call
 * should keep using the already-fetched SearchResultPage::$results directly.
 *
 * SPEC-v3-caching-chunking.md Phase D: when the active backend implements
 * ChunkSearchBackendInterface, candidates come from its chunk-level vector
 * index instead of a whole-document search — precise passages rather than a
 * (possibly truncated) whole-document snippet, and long documents are fully
 * searchable rather than cut off at the embedding provider's token limit.
 * Falls back to the pre-Phase-D whole-document search when the active backend
 * doesn't support chunking yet (IndexedSearchBackend, or MeilisearchBackend
 * before Phase E) — no hard dependency on chunking existing.
 */
class RagContextResolver
{
    private const int DEFAULT_RERANK_WIDTH = 20;

    public function __construct(
        private readonly SearchService $searchService,
        private readonly RerankerInterface $reranker,
        private readonly BackendRegistry $backendRegistry,
    ) {}

    /**
     * @param int<1, max> $topN
     * @return SearchResult[]
     */
    public function resolve(
        string $query,
        ?SiteLanguage $language,
        ?string $type,
        int $topN,
        int $rerankWidth = self::DEFAULT_RERANK_WIDTH,
    ): array {
        $candidates = $this->resolveCandidates($query, $language, $type, max($rerankWidth, $topN));

        if ($candidates === []) {
            return [];
        }

        return $this->reranker->rerank($query, $candidates, $topN);
    }

    /**
     * @return SearchResult[]
     */
    private function resolveCandidates(string $query, ?SiteLanguage $language, ?string $type, int $width): array
    {
        $backend = $this->backendRegistry->getActive();

        if ($backend instanceof ChunkSearchBackendInterface) {
            return $backend->searchChunks($query, $language, $type, $width);
        }

        return $this->searchService->search($query, $width, 0, $language, true, $type)->results;
    }
}
