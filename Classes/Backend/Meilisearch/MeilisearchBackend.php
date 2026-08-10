<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\ChunkDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\ChunkSearchBackendInterface;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Facet\FacetInterface;
use Maispace\MaiSearch\Facet\FacetRegistry;
use Maispace\MaiSearch\Facet\FacetValue;
use Maispace\MaiSearch\Facet\TypeFacet;
use Maispace\MaiSearch\Search\HybridRankFusionService;
use Maispace\MaiSearch\Service\EmbeddingTextTruncator;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Maispace\MaiSearch\Service\QueryEmbeddingCacheService;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Meilisearch implementation of SearchBackendInterface (SPEC-v2.md Phase 2b)
 * and ChunkSearchBackendInterface (SPEC-v3-caching-chunking.md Phase E).
 *
 * One index per language holds every record type, with `type` as a
 * filterable attribute — mirrors SolrBackend's shape (one query, all types,
 * type as a facet) rather than mai_meilisearch's original one-index-per-type
 * convention (decided explicitly; see IndexNameResolver).
 *
 * Chunks live in a **separate** index per language (IndexNameResolver::forChunks()),
 * not mixed into the main one. Discovered the hard way: once an index has an
 * embedder configured, Meilisearch requires *every* document written to that
 * index to carry a vector for it — a plain document add without `_vectors`
 * fails outright with `vector_embedding_error` rather than being written
 * without one. Regular documents never carry a vector, so a shared index
 * would mean every single ordinary document write starts failing the moment
 * an embedder is configured. The main index therefore never gets an embedder
 * configured on it at all.
 *
 * getCapabilities()->supportsVectorSearch is now true: chunk documents carry a
 * `userProvided` embedder vector (VectorStoreProvisioner/ProvisioningService
 * configure it on the chunk index only), used for chunk-level RAG retrieval
 * and the same HybridRankFusionService-based result-list rank fusion
 * SolrBackend uses — not Meilisearch's own built-in `hybrid`/`semanticRatio`
 * search parameter, which would blend chunk and whole-document results
 * inside Meilisearch itself rather than keeping them in the two separate
 * retrieval paths (and now separate indexes) this extension maintains.
 */
final class MeilisearchBackend implements SearchBackendInterface, ChunkSearchBackendInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const string TYPE_FILTER_ATTRIBUTE = 'type';

    /**
     * `userProvided` embedder name configured by ProvisioningService — shared
     * constant so index configuration (write side) and search/indexing (this
     * class) never drift apart.
     */
    public const string EMBEDDER_NAME = 'default';

    /** @see SolrBackend::RESULT_LIST_CHUNK_TOP_K for the same tuning rationale */
    private const int RESULT_LIST_CHUNK_TOP_K = 50;

    /**
     * @var array<string, true>
     */
    private array $ensuredIndexes = [];

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly IndexNameResolver $indexNameResolver,
        private readonly ResultFormatterRegistry $resultFormatterRegistry,
        private readonly FacetRegistry $facetRegistry,
        private readonly QueryEmbeddingCacheService $queryEmbeddingCache,
        private readonly ProviderRegistry $providerRegistry,
        private readonly HybridRankFusionService $rankFusion,
        private readonly EmbeddingTextTruncator $embeddingTextTruncator = new EmbeddingTextTruncator(),
    ) {}

    public function getIdentifier(): string
    {
        return 'meilisearch';
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

        $indexUid = $this->indexNameResolver->forLanguage($this->resolveLanguageCode($request->language));

        $searchParameters = [
            'limit' => $limit,
            'offset' => $request->offset,
            'facets' => [self::TYPE_FILTER_ATTRIBUTE],
            'showRankingScore' => true,
        ];

        $normalizedType = $this->normalizeTypeFilter($request->filters[self::TYPE_FILTER_ATTRIBUTE] ?? null);
        if ($normalizedType !== null) {
            $searchParameters['filter'] = sprintf('%s = "%s"', self::TYPE_FILTER_ATTRIBUTE, $normalizedType);
        }

        $response = $this->connectionFactory->getClient()->search($indexUid, $request->query, $searchParameters);
        $results = $this->buildResults($response);

        if ($request->ragEnabled) {
            $results = $this->applyRankFusion($results, $request->query, $request->language, $normalizedType);
        }

        return new SearchResultPage(
            $results,
            $response->estimatedTotalHits,
            $this->buildFacets($response->facetDistribution[self::TYPE_FILTER_ATTRIBUTE] ?? [], $normalizedType),
            $page,
            $limit,
        );
    }

    /**
     * @see SolrBackend::applyRankFusion() for the shared rationale — identical
     * logic, backend-agnostic HybridRankFusionService, different retrieval call
     * underneath (searchChunks() here talks to Meilisearch, not Solr).
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
     * query (SPEC-v2.md Phase 6) — only 'type' is requested as a Meilisearch facet
     * above, so any other declared facet is silently skipped rather than half-rendered.
     *
     * @param array<string, int> $counts
     * @return list<FacetInterface>
     */
    private function buildFacets(array $counts, ?string $activeType): array
    {
        $facets = [];

        foreach ($this->facetRegistry->getAllFacets() as $declaration) {
            if ($declaration->getName() !== self::TYPE_FILTER_ATTRIBUTE) {
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
        $indexUid = $this->indexNameResolver->forLanguage(is_string($languageCode) ? $languageCode : null);

        $client = $this->connectionFactory->getClient();
        $this->ensureIndex($client, $indexUid);

        $client->addDocuments($indexUid, [$this->toMeilisearchDocument($document)]);
    }

    public function removeDocument(string $type, int $uid): void
    {
        $indexUid = $this->indexNameResolver->forLanguage(null);

        $this->connectionFactory->getClient()->deleteDocument($indexUid, $type . '-' . $uid);
    }

    private function ensureIndex(MeilisearchClientInterface $client, string $indexUid): void
    {
        if (isset($this->ensuredIndexes[$indexUid])) {
            return;
        }

        $client->ensureIndex($indexUid, [self::TYPE_FILTER_ATTRIBUTE]);
        $this->ensuredIndexes[$indexUid] = true;
    }

    /**
     * Separate from ensureIndex() — the chunk index is never configured with an
     * embedder here (that's ProvisioningService's job, once, at provisioning
     * time); this only guarantees the index exists with the filterable
     * attributes removeChunks()'s delete-by-filter needs.
     */
    private function ensureChunkIndex(MeilisearchClientInterface $client, string $indexUid): void
    {
        if (isset($this->ensuredIndexes[$indexUid])) {
            return;
        }

        $client->ensureIndex($indexUid, ['parentType', 'parentUid']);
        $this->ensuredIndexes[$indexUid] = true;
    }

    private function toMeilisearchDocument(SearchDocumentInput $input): MeilisearchDocument
    {
        $document = (new MeilisearchDocument())
            ->withField('id', $input->type . '-' . $input->uid)
            ->withField('type', $input->type)
            ->withField('title', $input->title)
            ->withField('content', $input->content)
            ->withField('url', $input->url)
            ->withField('uid', $input->uid)
            ->withField('crdate', $input->crdate->getTimestamp())
            ->withField('boost', $input->boost)
            ->withField('rootline', implode(' | ', $input->rootline));

        foreach ($input->additionalFields as $key => $value) {
            if ($key === 'languageCode') {
                continue;
            }

            $document = $document->withField($key, $value);
        }

        return $document;
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

    private function resolveLanguageCode(?SiteLanguage $language): ?string
    {
        return $language?->getLocale()->getLanguageCode();
    }

    /**
     * @return list<SearchResult>
     */
    private function buildResults(SearchResponse $response): array
    {
        $results = [];

        foreach ($response->hits as $hit) {
            $type = $hit['type'] ?? '';
            if ($type === '') {
                continue;
            }

            $formatter = $this->resultFormatterRegistry->getFormatter($type);

            if ($formatter === null) {
                continue;
            }

            $results[] = $formatter->formatResult($this->toSolrShapedArray($hit));
        }

        return $results;
    }

    /**
     * Existing SearchResultFormatterInterface implementations (NewsIndexer,
     * FaqIndexer, ...) read Solr's `_s`/`_t`-suffixed field names directly.
     * Translating Meilisearch's plain-field hits into that same shape here —
     * rather than editing all ten indexers — keeps every existing formatter
     * working unchanged. Revisit once "reconcile the indexer contract"
     * generalizes SearchResultFormatterInterface itself.
     *
     * @param array<string, mixed> $hit
     * @return array<string, mixed>
     */
    private function toSolrShapedArray(array $hit): array
    {
        return [
            'type_s' => $hit['type'] ?? '',
            'title_s' => $hit['title'] ?? '',
            'title_t' => $hit['title'] ?? '',
            'content_t' => $hit['content'] ?? '',
            'url_s' => $hit['url'] ?? '',
            'uid_i' => $hit['uid'] ?? 0,
            'crdate_dt' => isset($hit['crdate'])
                ? (new \DateTime())->setTimestamp((int) $hit['crdate'])->format('Y-m-d\TH:i:s\Z')
                : null,
            'score' => $hit['_rankingScore'] ?? 0.0,
        ];
    }

    // ── ChunkSearchBackendInterface (SPEC-v3-caching-chunking.md Phase E) ───────

    public function indexChunk(ChunkDocumentInput $chunk): void
    {
        $vector = $this->embedChunk($chunk->content);

        if ($vector === []) {
            // No vector, no point writing a chunk document that can never be found
            // by searchChunks() — matches SolrBackend leaving the write to
            // IndexManagementService, which logs+continues on the same failure.
            return;
        }

        $indexUid = $this->indexNameResolver->forChunks($chunk->languageCode);
        $client = $this->connectionFactory->getClient();
        $this->ensureChunkIndex($client, $indexUid);

        $document = (new MeilisearchDocument())
            ->withField('id', $this->chunkId($chunk->parentType, $chunk->parentUid, $chunk->chunkIndex))
            ->withField('parentType', $chunk->parentType)
            ->withField('parentUid', $chunk->parentUid)
            ->withField('parentTitle', $chunk->parentTitle)
            ->withField('parentUrl', $chunk->parentUrl)
            ->withField('chunkIndex', $chunk->chunkIndex)
            ->withField('content', $chunk->content)
            ->withField('_vectors', [self::EMBEDDER_NAME => $vector]);

        $client->addDocuments($indexUid, [$document]);
    }

    public function removeChunk(string $parentType, int $parentUid, int $chunkIndex): void
    {
        $indexUid = $this->indexNameResolver->forChunks(null);

        $this->connectionFactory->getClient()->deleteDocument(
            $indexUid,
            $this->chunkId($parentType, $parentUid, $chunkIndex),
        );
    }

    public function removeChunks(string $parentType, int $parentUid): void
    {
        $indexUid = $this->indexNameResolver->forChunks(null);

        $this->connectionFactory->getClient()->deleteDocumentsByFilter(
            $indexUid,
            sprintf('parentType = "%s" AND parentUid = %d', $parentType, $parentUid),
        );
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

        $indexUid = $this->indexNameResolver->forChunks($this->resolveLanguageCode($language));

        $searchParameters = [
            'limit' => $topK,
            'vector' => $vector,
            'hybrid' => ['embedder' => self::EMBEDDER_NAME, 'semanticRatio' => 1.0],
            'showRankingScore' => true,
        ];

        $normalizedType = $this->normalizeTypeFilter($type);
        if ($normalizedType !== null) {
            $searchParameters['filter'] = sprintf('parentType = "%s"', $normalizedType);
        }

        try {
            $response = $this->connectionFactory->getClient()->search($indexUid, '', $searchParameters);
        } catch (\Throwable $e) {
            $this->logger?->warning('Chunk vector search failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return [];
        }

        return $this->buildChunkResults($response);
    }

    /**
     * @return list<float>
     */
    private function embedChunk(string $content): array
    {
        $provider = $this->providerRegistry->getActiveEmbeddingProvider();
        $text = $this->embeddingTextTruncator->truncate($content, $provider->getMaxInputTokens());

        try {
            return $provider->embed($text);
        } catch (\Throwable $e) {
            $this->logger?->warning('Chunk embedding failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
    }

    /**
     * @return list<SearchResult>
     */
    private function buildChunkResults(SearchResponse $response): array
    {
        $results = [];

        foreach ($response->hits as $hit) {
            $parentType = $hit['parentType'] ?? '';
            if ($parentType === '') {
                continue;
            }

            $results[] = new SearchResult(
                type: (string) $parentType,
                title: (string) ($hit['parentTitle'] ?? ''),
                snippet: (string) ($hit['content'] ?? ''),
                url: (string) ($hit['parentUrl'] ?? ''),
                icon: '',
                date: null,
                score: (float) ($hit['_rankingScore'] ?? 0.0),
            );
        }

        return $results;
    }

    private function chunkId(string $parentType, int $parentUid, int $chunkIndex): string
    {
        return sprintf('%s-%d-chunk-%d', $parentType, $parentUid, $chunkIndex);
    }
}
