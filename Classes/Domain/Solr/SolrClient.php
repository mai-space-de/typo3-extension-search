<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Solr;

use Solarium\Client;
use Solarium\Component\Facet\Field as FacetField;
use Solarium\Component\Result\Facet\Field as FacetFieldResult;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\Core\Client\Endpoint;
use Solarium\QueryType\Update\Query\Document as SolariumDocument;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Thin Solarium-backed Solr client for mai_search.
 *
 * Replaces the ext-solr SolrConnection / Read / Write / Admin service surface
 * with the subset of operations this extension actually uses.
 */
final class SolrClient implements SolrClientInterface
{
    private Client $client;

    public function __construct(
        private readonly Endpoint $endpoint,
        ?Client $client = null,
    ) {
        $this->client = $client ?? $this->createDefaultClient();
    }

    public function getEndpoint(): Endpoint
    {
        return $this->endpoint;
    }

    public function getCore(): string
    {
        return (string) $this->endpoint->getCore();
    }

    public function ping(): bool
    {
        try {
            $this->client->ping($this->client->createPing());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function search(SearchQuery $searchQuery): SearchResponse
    {
        $select = $this->client->createSelect();
        $select->setQuery($searchQuery->getQuery());
        $select->setStart($searchQuery->getStart());
        $select->setRows($searchQuery->getRows());

        foreach ($searchQuery->getFilterQueries() as $index => $filterQuery) {
            $fq = $select->createFilterQuery('fq' . $index)->setQuery($filterQuery['query']);
            if ($filterQuery['tag'] !== null && $filterQuery['tag'] !== '') {
                $fq->addTag($filterQuery['tag']);
            }
        }

        foreach ($searchQuery->getParams() as $name => $value) {
            $select->addParam($name, $value);
        }

        if ($searchQuery->getFacetFields() !== []) {
            $facetSet = $select->getFacetSet();
            $facetSet->setMinCount(1);
            foreach ($searchQuery->getFacetFields() as $key => $facetConfig) {
                /** @var FacetField $facet */
                $facet = $facetSet->createFacetField($key);
                $facet->setField($facetConfig['field']);
                foreach ($facetConfig['excludes'] as $exclude) {
                    $facet->addExclude($exclude);
                }
            }
        }

        $result = $this->client->select($select);

        $documents = [];
        foreach ($result->getDocuments() as $document) {
            $documents[] = $document->getFields();
        }

        $facetCounts = [];
        $facetSetResult = $result->getFacetSet();
        if ($facetSetResult !== null) {
            foreach (array_keys($searchQuery->getFacetFields()) as $key) {
                $facet = $facetSetResult->getFacet($key);
                if (!$facet instanceof FacetFieldResult) {
                    continue;
                }

                $values = [];
                foreach ($facet->getValues() as $value => $count) {
                    $values[(string) $value] = (int) $count;
                }
                $facetCounts[$key] = $values;
            }
        }

        return new SearchResponse(
            $documents,
            (int) ($result->getNumFound() ?? 0),
            $facetCounts,
        );
    }

    /**
     * @param list<Document> $documents
     */
    public function addDocuments(array $documents): void
    {
        $update = $this->client->createUpdate();
        $solariumDocuments = [];

        foreach ($documents as $document) {
            $solariumDocuments[] = new SolariumDocument($document->getFields());
        }

        $update->addDocuments($solariumDocuments);
        $this->client->update($update);
    }

    public function deleteByQuery(string $query): void
    {
        $update = $this->client->createUpdate();
        $update->addDeleteQuery($query);
        $this->client->update($update);
    }

    public function deleteByType(string $type): void
    {
        $this->deleteByQuery('type_s:' . trim($type));
    }

    public function commit(bool $expungeDeletes = false, bool $waitSearcher = false): void
    {
        $update = $this->client->createUpdate();
        $update->addCommit($expungeDeletes, $waitSearcher, false);
        $this->client->update($update);
    }

    public function getNumDocs(): int
    {
        $query = (new SearchQuery())
            ->setQuery('*:*')
            ->setRows(0);

        return $this->search($query)->getNumFound();
    }

    public function getServerVersion(): string
    {
        try {
            $api = $this->client->createApi([
                'version' => 'v1',
                'handler' => 'admin/info/system',
            ]);
            $result = $this->client->execute($api);
            $data = $result->getData();

            return (string) ($data['lucene']['solr-spec-version']
                ?? $data['lucene']['solr-impl-version']
                ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    private function createDefaultClient(): Client
    {
        $adapter = new Curl();
        $eventDispatcher = new EventDispatcher();
        $client = new Client($adapter, $eventDispatcher);
        $client->clearEndpoints();
        $client->addEndpoint($this->endpoint);
        $client->setDefaultEndpoint($this->endpoint);

        return $client;
    }
}
