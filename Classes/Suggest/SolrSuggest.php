<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Suggest;

use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;

final class SolrSuggest implements SuggestInterface
{
    public function __construct(
        private readonly ConnectionFactory $connectionFactory,
    ) {}

    public function getIdentifier(): string
    {
        return 'solr';
    }

    public function getSuggestions(string $query, int $limit = 10): array
    {
        if (trim($query) === '') {
            return [];
        }

        try {
            $connection = $this->connectionFactory->getConnection();

            $searchQuery = new SearchQuery();
            $searchQuery->setQuery('title_s:' . $this->escapeQuery($query) . '*')
                ->setRows($limit);

            $response = $connection->search($searchQuery);

            $suggestions = [];
            foreach ($response->getDocuments() as $document) {
                $title = $document['title_s'] ?? null;
                if ($title !== null && is_string($title)) {
                    $suggestions[] = $title;
                }
            }

            return $suggestions;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Mirrors SolrBackend::buildSolrQuery()'s escaping — a prefix query built by
     * naive string concatenation lets unescaped Solr special characters break the
     * query syntax (or, unescaped, be interpreted as query operators).
     */
    private function escapeQuery(string $query): string
    {
        return preg_replace(
            '/([+\-&|!(){}[\]^"~*?:\\\\\\/])/',
            '\\\\$1',
            trim($query),
        ) ?? '';
    }
}
