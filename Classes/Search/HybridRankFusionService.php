<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Search;

use Maispace\MaiSearch\Domain\Dto\SearchResult;

/**
 * Fuses a text-search ranking with a chunk-based semantic ranking using
 * Reciprocal Rank Fusion, backend-agnostically (SPEC-v3-caching-chunking.md
 * Phase D, Decision 3). Replaces SolrBackend's former Solr-specific
 * `{!rerank}`/`{!knn}` document-vector rerank — that mechanism required a
 * vector field on the same document being reranked, which no longer exists
 * now that vectors live only on chunk documents (see
 * ChunkSearchBackendInterface). Pattern ported from
 * `.lookup/smart-search`'s HybridSearchService (SPEC-v3.md 1.4): RRF compares
 * *ranks*, not raw scores, which is the point — BM25 relevance and cosine
 * similarity are on unrelated scales and cannot be combined arithmetically.
 *
 * Used identically by SolrBackend and MeilisearchBackend once both implement
 * ChunkSearchBackendInterface, so hybrid ranking is no longer a Solr-only
 * capability tied to a backend-specific query DSL.
 */
final class HybridRankFusionService
{
    private const int RRF_K = 60;

    /**
     * Reorders $textResults according to the combined rank of the text-search
     * signal and the semantic signal. Never adds or removes entries — a
     * semantic hit for a document that isn't part of $textResults (e.g. it
     * belongs on a different results page) is ignored; facets, total count,
     * and pagination stay governed entirely by the text search.
     *
     * @param list<SearchResult> $textResults Already-paginated text-search results, in rank order
     * @param list<SearchResult> $semanticResults Chunk-based semantic hits, in rank order, ideally
     *   already collapsed to one entry per parent document via collapseToBestPerParent()
     * @return list<SearchResult> $textResults reordered by fused rank
     */
    public function fuse(array $textResults, array $semanticResults): array
    {
        if ($textResults === [] || $semanticResults === []) {
            return $textResults;
        }

        $scores = [];
        foreach ($textResults as $rank => $result) {
            $scores[$result->url] = $this->reciprocalRank($rank);
        }

        foreach ($semanticResults as $rank => $result) {
            if (!isset($scores[$result->url])) {
                // Not part of this page's text results — fusion only reorders what's
                // already there, it never injects new documents into the page.
                continue;
            }

            $scores[$result->url] += $this->reciprocalRank($rank);
        }

        $reordered = $textResults;
        usort(
            $reordered,
            static fn(SearchResult $a, SearchResult $b): int => $scores[$b->url] <=> $scores[$a->url],
        );

        return $reordered;
    }

    /**
     * Keeps only the best-scoring chunk hit per parent document (identified by
     * URL, the same stable identifier AnswerCacheService tags on). Chunks of one
     * document are near-duplicates by construction for ranking purposes — without
     * this, a single long document could occupy every semantic-signal slot before
     * fuse() even runs. Deliberately not used for RAG context selection itself
     * (RagContextResolver), which keeps multiple chunks per document on purpose
     * (SPEC-v3-caching-chunking.md Decision 2) — this method exists only for the
     * result-list ranking signal.
     *
     * @param list<SearchResult> $chunkResults
     * @return list<SearchResult>
     */
    public function collapseToBestPerParent(array $chunkResults): array
    {
        $best = [];

        foreach ($chunkResults as $result) {
            if (!isset($best[$result->url]) || $result->score > $best[$result->url]->score) {
                $best[$result->url] = $result;
            }
        }

        return array_values($best);
    }

    private function reciprocalRank(int $rank): float
    {
        return 1.0 / (self::RRF_K + $rank + 1);
    }
}
