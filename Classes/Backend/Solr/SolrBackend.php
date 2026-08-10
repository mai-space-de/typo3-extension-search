<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Solr;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\ChunkDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\ChunkSearchBackendInterface;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\Document;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Domain\Solr\SearchResponse;
use Maispace\MaiSearch\Facet\FacetInterface;
use Maispace\MaiSearch\Facet\FacetRegistry;
use Maispace\MaiSearch\Facet\FacetValue;
use Maispace\MaiSearch\Facet\TypeFacet;
use Maispace\MaiSearch\Search\HybridRankFusionService;
use Maispace\MaiSearch\Service\QueryEmbeddingCacheService;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Solr implementation of SearchBackendInterface (SPEC-v2.md Phase 1) and
 * ChunkSearchBackendInterface (SPEC-v3-caching-chunking.md Phase D).
 *
 * Query-building logic here is unchanged from the pre-Phase-1 SearchService —
 * only moved and re-targeted at SearchRequest/SearchBackendInterface so it can
 * sit behind BackendRegistry alongside Meilisearch and indexed_search.
 *
 * The former document-level KNN hybrid rerank (`{!rerank}`/`{!knn}` over a
 * per-document `content_vector` field) is retired as of Phase D, Decision 3:
 * that field no longer exists on ordinary documents at all — vectors live
 * exclusively on chunk documents now (`type_s: 'chunk'`, excluded from every
 * ordinary query/facet below). The result-list ranking signal it used to
 * provide is replaced by HybridRankFusionService, fusing text-search rank with
 * a chunk-based semantic rank — see search()'s ragEnabled branch.
 */
final class SolrBackend implements SearchBackendInterface, ChunkSearchBackendInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const string TYPE_FACET_KEY = 'type';
    private const string CHUNK_TYPE = 'chunk';

    /**
     * How many chunk hits to retrieve as the semantic signal for the result-list
     * rank fusion, before collapsing to one per parent document. Wider than the
     * text-search page itself so a page-2 chunk hit can still confirm a page-1
     * document's ranking, narrower than RAG's own ragRerankWidth since this only
     * ever reorders — never extends — the already-paginated text results.
     */
    private const int RESULT_LIST_CHUNK_TOP_K = 50;

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ResultFormatterRegistry $resultFormatterRegistry,
        private readonly IndexManagementService $indexManagementService,
        private readonly QueryEmbeddingCacheService $queryEmbeddingCache,
        private readonly FacetRegistry $facetRegistry,
        private readonly HybridRankFusionService $rankFusion,
    ) {}

    public function getIdentifier(): string
    {
        return 'solr';
    }

    public function getCapabilities(): BackendCapabilities
    {
        return new BackendCapabilities(
            supportsRealtimeIndexing: true,
            supportsFacets: true,
            supportsVectorSearch: true,
            hasComparableRelevanceScore: true,
        );
    }

    public function search(SearchRequest $request): SearchResultPage
    {
        $limit = $request->limit;
        $page = $limit > 0 ? (int) floor($request->offset / $limit) + 1 : 1;

        if (trim($request->query) === '') {
            return new SearchResultPage([], 0, [], $page, $limit);
        }

        $connection = $this->connectionFactory->getConnection($request->language);
        $solrQuery = new SearchQuery();
        $solrQuery->setQuery($this->buildSolrQuery($request->query));
        $solrQuery->setRows($limit);
        $solrQuery->setStart($request->offset);
        $solrQuery->addFacetField(self::TYPE_FACET_KEY, 'type_s', ['typefilter']);
        // Chunk documents (SPEC-v3-caching-chunking.md Phase D) live in the same
        // core and share content_t, so they'd otherwise match text queries and leak
        // into the ordinary results grid and its type facet. Untagged (unlike the
        // typefilter fq below) so it's never excluded from facet counting either.
        $solrQuery->addFilterQuery('-type_s:' . self::CHUNK_TYPE);

        $normalizedType = $this->normalizeTypeFilter($request->filters[self::TYPE_FACET_KEY] ?? null);
        if ($normalizedType !== null) {
            $solrQuery->addFilterQuery('type_s:' . $normalizedType, 'typefilter');
        }

        $response = $connection->search($solrQuery);
        $results = $this->buildResults($response);

        if ($request->ragEnabled) {
            $results = $this->applyRankFusion($results, $request->query, $request->language, $normalizedType);
        }

        return new SearchResultPage(
            $results,
            $response->getNumFound(),
            $this->buildFacets($response->getFacetCountsFor(self::TYPE_FACET_KEY), $normalizedType),
            $page,
            $limit,
        );
    }

    /**
     * Reorders $results by fusing their text-search rank with a chunk-based
     * semantic rank (SPEC-v3-caching-chunking.md Phase D, Decision 3). Never
     * changes which documents are on the page or their count — only their order.
     *
     * @param list<SearchResult> $results
     * @return list<SearchResult>
     */
    private function applyRankFusion(array $results, string $query, ?SiteLanguage $language, ?string $type): array
    {
        if ($results === []) {
            return $results;
        }

        $semanticHits = $this->searchChunks($query, $language, $type, self::RESULT_LIST_CHUNK_TOP_K);

        if ($semanticHits === []) {
            return $results;
        }

        return $this->rankFusion->fuse($results, $this->rankFusion->collapseToBestPerParent($semanticHits));
    }

    /**
     * Translates FacetRegistry's declarations into hydrated facet results for this
     * query (SPEC-v2.md Phase 6) — only 'type' has a Solr facet.field wired above,
     * so any other declared facet is silently skipped rather than half-rendered.
     *
     * @param array<string, int> $counts
     * @return list<FacetInterface>
     */
    private function buildFacets(array $counts, ?string $activeType): array
    {
        $facets = [];

        foreach ($this->facetRegistry->getAllFacets() as $declaration) {
            if ($declaration->getName() !== self::TYPE_FACET_KEY) {
                continue;
            }

            $values = [];
            foreach ($counts as $value => $count) {
                $values[] = new FacetValue((string) $value, (string) $value, $count, (string) $value === $activeType);
            }

            $facets[] = new TypeFacet(
                $declaration->getName(),
                $declaration->getLabel(),
                $values,
                $activeType !== null ? [$activeType] : [],
            );
        }

        return $facets;
    }

    public function indexDocument(SearchDocumentInput $document): void
    {
        $languageCode = $document->additionalFields['languageCode'] ?? null;

        $this->indexManagementService->addDocumentForLanguageCode(
            $this->toSolrDocument($document),
            is_string($languageCode) ? $languageCode : null,
            $document->contentChanged,
        );
    }

    public function removeDocument(string $type, int $uid): void
    {
        $this->indexManagementService->deleteRecord($type, $uid);
    }

    /**
     * Mirrors AbstractIndexer::createDocument()'s field mapping. Duplicated
     * here (rather than reused) because indexers still build a Solr Document
     * directly today — this becomes the single source of truth once Phase 2
     * moves indexers onto SearchDocumentInput.
     */
    private function toSolrDocument(SearchDocumentInput $input): Document
    {
        $document = new Document();
        $document->setField('id', $input->type . '-' . $input->uid);
        $document->setField('type_s', $input->type);
        $document->setField('title_s', $input->title);
        $document->setField('title_t', $input->title);
        $document->setField('content_t', $input->content);
        $document->setField('url_s', $input->url);
        $document->setField('uid_i', $input->uid);
        $document->setField('crdate_dt', $input->crdate->format('Y-m-d\TH:i:s\Z'));
        $document->setField('boost_i', (int) $input->boost);
        $document->setField('rootline_s', implode(' | ', $input->rootline));

        foreach ($input->additionalFields as $key => $value) {
            if ($key === 'languageCode') {
                continue;
            }

            $document->setField($key, $value);
        }

        return $document;
    }

    /**
     * Bare DDEV Solr cores have no copyField into `_text_`; search title and body fields explicitly.
     */
    private function buildSolrQuery(string $query): string
    {
        $escaped = preg_replace(
            '/([+\-&|!(){}[\]^"~*?:\\\\\\/])/',
            '\\\\$1',
            trim($query),
        );

        return sprintf('(title_t:(%s) OR content_t:(%s))', $escaped, $escaped);
    }

    private function normalizeTypeFilter(?string $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $normalized = trim($type);
        if ($normalized === '' || !preg_match('/^[a-z0-9_-]+$/i', $normalized)) {
            return null;
        }

        return $normalized;
    }

    /**
     * @return list<SearchResult>
     */
    private function buildResults(SearchResponse $response): array
    {
        $results = [];

        foreach ($response->getDocuments() as $docArray) {
            $type = $docArray['type_s'] ?? '';
            if ($type === '') {
                continue;
            }

            $formatter = $this->resultFormatterRegistry->getFormatter($type);

            if ($formatter === null) {
                continue;
            }

            $results[] = $formatter->formatResult($docArray);
        }

        return $results;
    }

    // ── ChunkSearchBackendInterface (SPEC-v3-caching-chunking.md Phase D) ───────

    public function indexChunk(ChunkDocumentInput $chunk): void
    {
        $this->indexManagementService->addDocumentForLanguageCode(
            $this->toChunkSolrDocument($chunk),
            $chunk->languageCode,
        );
    }

    public function removeChunk(string $parentType, int $parentUid, int $chunkIndex): void
    {
        $this->indexManagementService->deleteById($this->chunkId($parentType, $parentUid, $chunkIndex));
    }

    public function removeChunks(string $parentType, int $parentUid): void
    {
        $this->indexManagementService->deleteByQuery(sprintf(
            'type_s:%s AND parent_type_s:%s AND parent_uid_i:%d',
            self::CHUNK_TYPE,
            $parentType,
            $parentUid,
        ));
    }

    public function searchChunks(string $query, ?SiteLanguage $language, ?string $type, int $topK): array
    {
        if (trim($query) === '' || $topK < 1) {
            return [];
        }

        try {
            $vector = $this->queryEmbeddingCache->embed($query);
        } catch (\RuntimeException) {
            return [];
        }

        if ($vector === []) {
            return [];
        }

        $vectorString = '[' . implode(',', $vector) . ']';
        $solrQuery = new SearchQuery();
        $solrQuery->setQuery(sprintf('{!knn f=%s topK=%d}%s', SchemaManager::VECTOR_FIELD_NAME, $topK, $vectorString));
        $solrQuery->setRows($topK);
        $solrQuery->addFilterQuery('type_s:' . self::CHUNK_TYPE);

        $normalizedType = $this->normalizeTypeFilter($type);
        if ($normalizedType !== null) {
            $solrQuery->addFilterQuery('parent_type_s:' . $normalizedType);
        }

        try {
            $response = $this->connectionFactory->getConnection($language)->search($solrQuery);
        } catch (\Throwable $e) {
            $this->logger?->warning('Chunk KNN search failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return [];
        }

        return $this->buildChunkResults($response);
    }

    private function toChunkSolrDocument(ChunkDocumentInput $chunk): Document
    {
        $document = new Document();
        $document->setField('id', $this->chunkId($chunk->parentType, $chunk->parentUid, $chunk->chunkIndex));
        $document->setField('type_s', self::CHUNK_TYPE);
        $document->setField('parent_type_s', $chunk->parentType);
        $document->setField('parent_uid_i', $chunk->parentUid);
        $document->setField('parent_title_s', $chunk->parentTitle);
        $document->setField('parent_url_s', $chunk->parentUrl);
        $document->setField('chunk_index_i', $chunk->chunkIndex);
        $document->setField('content_t', $chunk->content);

        return $document;
    }

    /**
     * @return list<SearchResult>
     */
    private function buildChunkResults(SearchResponse $response): array
    {
        $results = [];

        foreach ($response->getDocuments() as $docArray) {
            $parentType = $docArray['parent_type_s'] ?? '';
            if ($parentType === '') {
                continue;
            }

            $results[] = new SearchResult(
                type: (string) $parentType,
                title: (string) ($docArray['parent_title_s'] ?? ''),
                snippet: (string) ($docArray['content_t'] ?? ''),
                url: (string) ($docArray['parent_url_s'] ?? ''),
                icon: '',
                date: null,
                score: (float) ($docArray['score'] ?? 0.0),
            );
        }

        return $results;
    }

    private function chunkId(string $parentType, int $parentUid, int $chunkIndex): string
    {
        return sprintf('%s-%d-chunk-%d', $parentType, $parentUid, $chunkIndex);
    }
}
