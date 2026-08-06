<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Indexer;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Service\BackendRegistry;

/**
 * SPEC-v2.md Phase 2c: indexers build a backend-agnostic SearchDocumentInput
 * and go through BackendRegistry — they no longer know or care whether Solr,
 * Meilisearch, or indexed_search is active. `ConnectionFactory`/
 * `PersistenceManager` are gone: the former was only ever used by
 * removeRecord() (now the shared removeDocument() helper), the latter was
 * injected but never actually used by any subclass.
 */
abstract class AbstractIndexer implements SearchIndexerInterface
{
    protected BackendRegistry $backendRegistry;

    public function injectBackendRegistry(BackendRegistry $backendRegistry): void
    {
        $this->backendRegistry = $backendRegistry;
    }

    /**
     * @param string[] $rootline Breadcrumb titles from site root to the record's parent page
     */
    protected function createDocument(
        string $type,
        int $uid,
        string $title,
        string $content,
        string $url,
        \DateTimeInterface $crdate,
        float $boost,
        array $rootline = [],
    ): SearchDocumentInput {
        return new SearchDocumentInput(
            type: $type,
            uid: $uid,
            title: $title,
            content: $content,
            url: $url,
            crdate: $crdate,
            boost: $boost,
            rootline: $rootline,
        );
    }

    protected function sendDocument(SearchDocumentInput $document, ?string $languageCode = null): void
    {
        if ($languageCode !== null) {
            $document = $document->withAdditionalField('languageCode', $languageCode);
        }

        $this->backendRegistry->getActive()->indexDocument($document);
    }

    protected function removeDocument(int $uid): void
    {
        $this->backendRegistry->getActive()->removeDocument($this->getType(), $uid);
    }

    public function getBoost(string $type): float
    {
        return 1.0;
    }

    abstract protected function buildContent(object $record): string;

    abstract protected function buildUrl(object $record): string;

    abstract protected function getRecordsForIndexing(IndexingContext $context): iterable;
}
