<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Reranking\RerankerInterface;
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
 */
class RagContextResolver
{
    private const int DEFAULT_RERANK_WIDTH = 20;

    public function __construct(
        private readonly SearchService $searchService,
        private readonly RerankerInterface $reranker,
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
        $candidates = $this->searchService->search(
            $query,
            max($rerankWidth, $topN),
            0,
            $language,
            true,
            $type,
        )->results;

        if ($candidates === []) {
            return [];
        }

        return $this->reranker->rerank($query, $candidates, $topN);
    }
}
