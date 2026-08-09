<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Solr;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
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
use Maispace\MaiSearch\Service\ProviderRegistry;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;

/**
 * Solr implementation of SearchBackendInterface (SPEC-v2.md Phase 1).
 *
 * Query-building logic here is unchanged from the pre-Phase-1 SearchService —
 * only moved and re-targeted at SearchRequest/SearchBackendInterface so it can
 * sit behind BackendRegistry alongside Meilisearch and indexed_search.
 *
 * KNN hybrid re-rank tuning (topK/weight) intentionally stays a private
 * constant here rather than a SearchRequest field: no caller has ever
 * overridden the defaults, and it's a Solr-specific concept that shouldn't
 * leak into the backend-agnostic request DTO.
 */
final class SolrBackend implements SearchBackendInterface
{
    private const int DEFAULT_KNN_TOP_K = 100;
    private const string TYPE_FACET_KEY = 'type';

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ResultFormatterRegistry $resultFormatterRegistry,
        private readonly IndexManagementService $indexManagementService,
        private readonly ProviderRegistry $providerRegistry,
        private readonly FacetRegistry $facetRegistry,
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

        $normalizedType = $this->normalizeTypeFilter($request->filters[self::TYPE_FACET_KEY] ?? null);
        if ($normalizedType !== null) {
            $solrQuery->addFilterQuery('type_s:' . $normalizedType, 'typefilter');
        }

        $this->applyHybridKnnReRank($solrQuery, $request->query, $request->ragEnabled);

        $response = $connection->search($solrQuery);

        return new SearchResultPage(
            $this->buildResults($response),
            $response->getNumFound(),
            $this->buildFacets($response->getFacetCountsFor(self::TYPE_FACET_KEY), $normalizedType),
            $page,
            $limit,
        );
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

    private function applyHybridKnnReRank(
        SearchQuery $solrQuery,
        string $query,
        bool $ragEnabled,
    ): void {
        if (!$ragEnabled) {
            return;
        }

        try {
            $vector = $this->providerRegistry->getActiveEmbeddingProvider()->embed($query);
        } catch (\RuntimeException) {
            return;
        }

        if ($vector === []) {
            return;
        }

        $this->addKnnReRankParam($solrQuery, $vector);
    }

    /**
     * `rq` must be a Solr RankQuery — a bare `{!knn ...}` query there is
     * rejected ("rq parameter must be a RankQuery"), since KNN's query parser
     * produces a plain Query, not a RankQuery. The KNN query has to be wrapped
     * in `{!rerank}` (ReRankQParserPlugin) instead, referencing the actual KNN
     * clause via a separate `rqq` param — this is the documented pattern for
     * hybrid text+vector search since Solr didn't add a first-class
     * `{!knn}`-as-rerank shortcut.
     *
     * DEFAULT_KNN_WEIGHT is currently always 1.0 (no caller has ever tuned it),
     * matching reRankWeight=1 below — reintroduce as a param if that changes.
     *
     * @param list<float> $vector
     */
    private function addKnnReRankParam(SearchQuery $solrQuery, array $vector): void
    {
        $vectorString = '[' . implode(',', $vector) . ']';

        $solrQuery->addParam(
            'rq',
            sprintf('{!rerank reRankQuery=$rqq reRankDocs=%d reRankWeight=1}', self::DEFAULT_KNN_TOP_K),
        );
        $solrQuery->addParam(
            'rqq',
            sprintf('{!knn f=%s topK=%d}%s', SchemaManager::VECTOR_FIELD_NAME, self::DEFAULT_KNN_TOP_K, $vectorString),
        );
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
}
