<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Backend\Meilisearch;

/**
 * Thin abstraction over the Meilisearch SDK so callers depend on this
 * interface, not on `\Meilisearch\Client` directly (DIP) — keeps the SDK
 * swappable and MeilisearchBackend testable without a running server.
 */
interface MeilisearchClientInterface
{
    /**
     * @param list<string> $filterableAttributes
     */
    public function ensureIndex(string $indexUid, array $filterableAttributes = [], string $primaryKey = 'id'): void;

    /**
     * @param MeilisearchDocument[] $documents
     */
    public function addDocuments(string $indexUid, array $documents): void;

    public function deleteDocument(string $indexUid, string|int $documentId): void;

    public function deleteAllDocuments(string $indexUid): void;

    /**
     * @param array<string, mixed> $searchParameters
     */
    public function search(string $indexUid, string $query, array $searchParameters = []): SearchResponse;
}
