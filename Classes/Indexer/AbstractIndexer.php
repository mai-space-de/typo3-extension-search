<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Indexer;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Event\AfterDocumentBuiltEvent;
use Maispace\MaiSearch\Event\BeforeDocumentBuiltEvent;
use Maispace\MaiSearch\Service\BackendRegistry;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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

    protected ?EventDispatcherInterface $eventDispatcher = null;

    public function injectBackendRegistry(BackendRegistry $backendRegistry): void
    {
        $this->backendRegistry = $backendRegistry;
    }

    public function injectEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
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

    /**
     * Build a document with PSR-14 events dispatched before and after.
     *
     * Indexers should call this instead of createDocument() directly to enable
     * third-party listeners to inspect/modify the document.
     */
    protected function buildDocumentWithEvents(
        object $record,
        IndexingContext $context,
        string $type,
        int $uid,
        string $title,
        string $content,
        string $url,
        \DateTimeInterface $crdate,
        float $boost,
        array $rootline = [],
    ): SearchDocumentInput {
        $this->eventDispatcher?->dispatch(
            new BeforeDocumentBuiltEvent($record, $context, $type),
        );

        $document = $this->createDocument(
            $type,
            $uid,
            $title,
            $content,
            $url,
            $crdate,
            $boost,
            $rootline,
        );

        $this->eventDispatcher?->dispatch(
            new AfterDocumentBuiltEvent($document, $record, $context, $type),
        );

        return $document;
    }

    protected function removeDocument(int $uid): void
    {
        $this->backendRegistry->getActive()->removeDocument($this->getType(), $uid);
    }

    public function getBoost(string $type): float
    {
        return 1.0;
    }

    /**
     * Default implementation: return the first table this indexer supports.
     * Subclasses handling multiple tables should override this.
     */
    public function getTableName(): string
    {
        // This is a fallback; concrete indexers should override
        return '';
    }

    /**
     * Default implementation: iterate through getRecordsForIndexing() and extract UIDs.
     * Subclasses can override for better performance if they have a direct query.
     *
     * @return iterable<int>
     */
    public function getRecordUidsForReindex(IndexingContext $context): iterable
    {
        $uids = [];
        foreach ($this->getRecordsForIndexing($context) as $record) {
            if (isset($record->uid)) {
                $uids[] = (int) $record->uid;
            }
        }

        return $uids;
    }

    abstract protected function buildContent(object $record): string;

    abstract protected function buildUrl(object $record): string;

    abstract protected function getRecordsForIndexing(IndexingContext $context): iterable;
}
