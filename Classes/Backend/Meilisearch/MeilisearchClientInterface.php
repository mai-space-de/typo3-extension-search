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

    /**
     * Deletes every document matching a Meilisearch filter expression — used to
     * remove all chunks of one parent document in a single call
     * (SPEC-v3-caching-chunking.md Phase E, ChunkSearchBackendInterface::removeChunks()).
     * The filtered fields must be declared filterable via ensureIndex().
     */
    public function deleteDocumentsByFilter(string $indexUid, string $filter): void;

    public function deleteAllDocuments(string $indexUid): void;

    /**
     * Configures a `userProvided` embedder (SPEC-v3-caching-chunking.md Phase E) —
     * vectors are supplied by mai_search's own ProviderRegistry, not computed by
     * Meilisearch itself, consistent with SolrBackend's KNN field. Requires the
     * vectorStore experimental feature to be enabled first (VectorStoreProvisioner).
     */
    public function configureEmbedder(string $indexUid, string $embedderName, int $dimensions): void;

    /**
     * @param array<string, mixed> $searchParameters
     */
    public function search(string $indexUid, string $query, array $searchParameters = []): SearchResponse;

    /**
     * @return array<string, mixed>|null
     */
    public function health(): ?array;

    /**
     * @return array<string, mixed>
     */
    public function stats(): array;
}
