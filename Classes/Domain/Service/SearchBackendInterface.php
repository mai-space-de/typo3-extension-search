<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;

/**
 * The central abstraction unifying Solr, Meilisearch, and indexed_search behind
 * one API (SPEC-v2.md). SearchService and IndexManagementService delegate to
 * whichever implementation BackendRegistry resolves as active; neither the
 * frontend plugin nor third-party indexers need to know which one is running.
 */
interface SearchBackendInterface
{
    /**
     * Stable identifier used for backend selection, e.g. 'solr', 'meilisearch', 'indexed_search'.
     */
    public function getIdentifier(): string;

    public function getCapabilities(): BackendCapabilities;

    public function search(SearchRequest $request): SearchResultPage;

    /**
     * @throws \RuntimeException When getCapabilities()->supportsRealtimeIndexing is false
     */
    public function indexDocument(SearchDocumentInput $document): void;

    public function removeDocument(string $type, int $uid): void;
}
