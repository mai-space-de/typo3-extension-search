<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\ChunkDocumentInput;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Optional capability a SearchBackendInterface implementation can additionally
 * offer: a chunk-level vector index used for precise RAG context retrieval and
 * for the result-list hybrid rank fusion (SPEC-v3-caching-chunking.md Phase D,
 * Decision 3).
 *
 * Deliberately a separate, additive interface rather than new methods on
 * SearchBackendInterface — same layering already established by
 * StreamingChatProviderInterface on top of ChatProviderInterface. Callers
 * (RagContextResolver, SolrBackend/MeilisearchBackend's own search()) check
 * `instanceof ChunkSearchBackendInterface` and fall back to document-level
 * retrieval when the active backend doesn't implement it (IndexedSearchBackend
 * never will).
 */
interface ChunkSearchBackendInterface
{
    public function indexChunk(ChunkDocumentInput $chunk): void;

    public function removeChunk(string $parentType, int $parentUid, int $chunkIndex): void;

    /**
     * Removes every chunk belonging to one parent document — used when the
     * parent itself is deleted, as opposed to removeChunk() for a single
     * orphaned trailing chunk of a shrunk-but-still-existing document.
     */
    public function removeChunks(string $parentType, int $parentUid): void;

    /**
     * @return list<SearchResult> Chunk hits shaped as SearchResult (snippet = chunk
     *   text, title/url/type = the parent document's), ranked by vector similarity.
     *   May contain multiple entries per parent document — callers that want at
     *   most one per parent (SolrBackend's result-list fusion) collapse explicitly
     *   via HybridRankFusionService::collapseToBestPerParent(); RagContextResolver
     *   deliberately does not (SPEC-v3-caching-chunking.md Decision 2).
     */
    public function searchChunks(string $query, ?SiteLanguage $language, ?string $type, int $topK): array;
}
