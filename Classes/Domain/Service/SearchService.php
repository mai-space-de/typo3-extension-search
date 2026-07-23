<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Domain\Solr\SearchResponse;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

class SearchService implements SingletonInterface
{
    private const int DEFAULT_KNN_TOP_K = 100;
    private const float DEFAULT_KNN_WEIGHT = 1.0;
    private const string TYPE_FACET_KEY = 'type';

    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ResultFormatterRegistry $resultFormatterRegistry,
        private readonly ?VectorEmbeddingInterface $embeddingService = null,
    ) {}

    /**
     * Execute a search query against Solr.
     *
     * When $ragEnabled is true and an embedding service is available, the query
     * is enhanced with a KNN vector re-rank that combines BM25 text relevance
     * with semantic vector proximity for hybrid scoring.
     *
     * @param string $query The user-entered search query
     * @param int $limit Maximum number of results to return
     * @param int $offset Zero-based offset for pagination
     * @param SiteLanguage|null $language Current site language for core selection
     * @param bool $ragEnabled Whether to enable hybrid vector+text search
     * @param int $knnTopK Number of nearest neighbours to consider for KNN re-ranking
     * @param float $knnWeight Weight multiplier applied to the KNN re-rank score
     * @param string|null $type Optional type_s filter (news, page, events, …)
     */
    public function search(
        string $query,
        int $limit = 20,
        int $offset = 0,
        ?SiteLanguage $language = null,
        bool $ragEnabled = false,
        int $knnTopK = self::DEFAULT_KNN_TOP_K,
        float $knnWeight = self::DEFAULT_KNN_WEIGHT,
        ?string $type = null,
    ): SearchResultPage {
        $page = $limit > 0 ? (int) floor($offset / $limit) + 1 : 1;

        if (trim($query) === '') {
            return new SearchResultPage([], 0, [], $page, $limit);
        }

        $connection = $this->connectionFactory->getConnection($language);
        $solrQuery = new SearchQuery();
        $solrQuery->setQuery($this->buildSolrQuery($query));
        $solrQuery->setRows($limit);
        $solrQuery->setStart($offset);
        $solrQuery->addFacetField(self::TYPE_FACET_KEY, 'type_s', ['typefilter']);

        $normalizedType = $this->normalizeTypeFilter($type);
        if ($normalizedType !== null) {
            $solrQuery->addFilterQuery('type_s:' . $normalizedType, 'typefilter');
        }

        $this->applyHybridKnnReRank($solrQuery, $query, $ragEnabled, $knnTopK, $knnWeight);

        $response = $connection->search($solrQuery);

        return new SearchResultPage(
            $this->buildResults($response),
            $response->getNumFound(),
            $response->getFacetCountsFor(self::TYPE_FACET_KEY),
            $page,
            $limit,
        );
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
        int $knnTopK,
        float $knnWeight,
    ): void {
        if (!$ragEnabled || $this->embeddingService === null || $knnTopK < 1) {
            return;
        }

        try {
            $vector = $this->embeddingService->embedText($query);
        } catch (\RuntimeException) {
            return;
        }

        if ($vector === []) {
            return;
        }

        $this->addKnnReRankParam($solrQuery, $vector, $knnTopK, $knnWeight);
    }

    /**
     * @param list<float> $vector
     */
    private function addKnnReRankParam(
        SearchQuery $solrQuery,
        array $vector,
        int $knnTopK,
        float $knnWeight,
    ): void {
        $vectorString = '[' . implode(',', $vector) . ']';
        $rqValue = sprintf(
            '{!knn f=%s topK=%d}%s',
            SchemaManager::VECTOR_FIELD_NAME,
            $knnTopK,
            $vectorString,
        );

        if ($knnWeight !== 1.0) {
            $rqValue .= '^' . $knnWeight;
        }

        $solrQuery->addParam('rq', $rqValue);
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
