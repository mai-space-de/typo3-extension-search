<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Solr;

use Solarium\Core\Client\Endpoint;

interface SolrClientInterface
{
    public function getEndpoint(): Endpoint;

    public function getCore(): string;

    public function ping(): bool;

    public function search(SearchQuery $searchQuery): SearchResponse;

    /**
     * @param list<Document> $documents
     */
    public function addDocuments(array $documents): void;

    public function deleteByQuery(string $query): void;

    public function deleteByType(string $type): void;

    public function commit(bool $expungeDeletes = false, bool $waitSearcher = false): void;

    public function getNumDocs(): int;

    public function getServerVersion(): string;
}
