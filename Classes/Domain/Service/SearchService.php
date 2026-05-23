<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use ApacheSolrForTypo3\Solr\Domain\Search\Query\Query;
use ApacheSolrForTypo3\Solr\System\Solr\ResponseAdapter;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use TYPO3\CMS\Core\SingletonInterface;

class SearchService implements SingletonInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
        private readonly ResultFormatterRegistry $resultFormatterRegistry,
    ) {}

    /**
     * @return SearchResult[]
     */
    public function search(string $query, int $limit = 20, int $offset = 0): array
    {
        if (trim($query) === '') {
            return [];
        }

        $connection = $this->connectionFactory->getConnection();
        $solrQuery = new Query();
        $solrQuery->setQuery($query);
        $solrQuery->setRows($limit);
        $solrQuery->setStart($offset);

        $response = $connection->getReadService()->search($solrQuery);

        return $this->buildResults($response);
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
