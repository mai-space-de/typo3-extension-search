<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use ApacheSolrForTypo3\Solr\Domain\Search\Query\Query;
use ApacheSolrForTypo3\Solr\System\Solr\ResponseAdapter;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

class SearchService implements SingletonInterface
{
    private const int DEFAULT_KNN_TOP_K = 100;
    private const float DEFAULT_KNN_WEIGHT = 1.0;

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
     *
     * @return SearchResult[]
     */
    public function search(
        string $query,
        int $limit = 20,
        int $offset = 0,
        ?SiteLanguage $language = null,
        bool $ragEnabled = false,
        int $knnTopK = self::DEFAULT_KNN_TOP_K,
        float $knnWeight = self::DEFAULT_KNN_WEIGHT,
    ): array {
        if (trim($query) === '') {
            return [];
        }

        $connection = $this->connectionFactory->getConnection($language);
        $solrQuery = new Query();
        $solrQuery->setQuery($query);
        $solrQuery->setRows($limit);
        $solrQuery->setStart($offset);

        $this->applyHybridKnnReRank($solrQuery, $query, $ragEnabled, $knnTopK, $knnWeight);

        $response = $connection->getReadService()->search($solrQuery);

        return $this->buildResults($response);
    }

    private function applyHybridKnnReRank(
        Query $solrQuery,
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
        Query $solrQuery,
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
     * @return SearchResult[]
     */
    private function buildResults(ResponseAdapter $response): array
    {
        $results = [];

        if (!isset($response->response->docs) || !is_array($response->response->docs)) {
            return $results;
        }

        foreach ($response->response->docs as $doc) {
            $docArray = json_decode(json_encode($doc), true) ?? [];
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
