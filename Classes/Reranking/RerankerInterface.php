<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Reranking;

use Maispace\MaiSearch\Domain\Dto\SearchResult;

/**
 * Reorders a widened set of retrieval candidates by relevance to the query,
 * for RAG context selection (SPEC-v2.md Phase 7 addendum).
 *
 * Implementations MUST NOT rewrite `score` on the returned SearchResult
 * objects — score stays whatever the search backend assigned, and the new
 * ranking is conveyed by array order alone. Lesson from
 * `.lookup/smart-search`'s LlmReranker: it originally replaced score with a
 * rank-derived value, which silently broke a downstream threshold filter
 * because two incompatible scales ended up sharing one field.
 */
interface RerankerInterface
{
    /**
     * @param SearchResult[] $candidates
     * @param int<1, max> $topN
     * @return SearchResult[] Reordered by relevance, most relevant first, truncated to $topN.
     */
    public function rerank(string $query, array $candidates, int $topN): array;
}
