<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;

final class MeilisearchClient implements MeilisearchClientInterface
{
    public function __construct(private readonly Client $client) {}

    public function ensureIndex(string $indexUid, array $filterableAttributes = [], string $primaryKey = 'id'): void
    {
        try {
            $this->client->getIndex($indexUid);
        } catch (ApiException) {
            $this->client->createIndex($indexUid, ['primaryKey' => $primaryKey]);
        }

        if ($filterableAttributes !== []) {
            $this->client->index($indexUid)->updateFilterableAttributes($filterableAttributes);
        }
    }

    public function addDocuments(string $indexUid, array $documents): void
    {
        $payload = array_map(
            static fn(MeilisearchDocument $document): array => $document->toArray(),
            $documents,
        );

        if ($payload === []) {
            return;
        }

        $this->client->index($indexUid)->addDocuments($payload);
    }

    public function deleteDocument(string $indexUid, string|int $documentId): void
    {
        $this->client->index($indexUid)->deleteDocument($documentId);
    }

    public function deleteAllDocuments(string $indexUid): void
    {
        $this->client->index($indexUid)->deleteAllDocuments();
    }

    public function search(string $indexUid, string $query, array $searchParameters = []): SearchResponse
    {
        $result = $this->client->index($indexUid)->search($query, $searchParameters)->toArray();

        return new SearchResponse(
            hits: $result['hits'] ?? [],
            estimatedTotalHits: (int) ($result['estimatedTotalHits'] ?? 0),
            processingTimeMs: (int) ($result['processingTimeMs'] ?? 0),
            facetDistribution: $result['facetDistribution'] ?? [],
        );
    }

    public function health(): ?array
    {
        return $this->client->health();
    }

    public function stats(): array
    {
        return $this->client->stats();
    }
}
