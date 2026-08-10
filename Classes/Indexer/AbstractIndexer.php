<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Indexer;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Event\AfterDocumentBuiltEvent;
use Maispace\MaiSearch\Event\BeforeDocumentBuiltEvent;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\BackendRegistry;
use Maispace\MaiSearch\Service\ChunkIndexingService;
use Maispace\MaiSearch\Service\ContentHashService;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
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

    protected ?ContentHashService $contentHashService = null;

    protected ?AnswerCacheService $answerCacheService = null;

    protected ?ChunkIndexingService $chunkIndexingService = null;

    public function injectBackendRegistry(BackendRegistry $backendRegistry): void
    {
        $this->backendRegistry = $backendRegistry;
    }

    public function injectEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function injectContentHashService(ContentHashService $contentHashService): void
    {
        $this->contentHashService = $contentHashService;
    }

    public function injectAnswerCacheService(AnswerCacheService $answerCacheService): void
    {
        $this->answerCacheService = $answerCacheService;
    }

    public function injectChunkIndexingService(ChunkIndexingService $chunkIndexingService): void
    {
        $this->chunkIndexingService = $chunkIndexingService;
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

        $document = $document->withContentChanged($this->trackContentChange($document));

        $this->backendRegistry->getActive()->indexDocument($document);

        // Independent of $document->contentChanged — see ChunkIndexingService's own
        // docblock for why chunk indexing needs its own per-chunk hash comparison
        // rather than trusting the whole-document flag (SPEC-v3-caching-chunking.md
        // Phase D).
        $this->chunkIndexingService?->indexChunks($document);
    }

    /**
     * Gates RAG answer-cache invalidation on an actual content change (SPEC-v2.md
     * Phase 7 addendum) — a save that only touches metadata (timestamps, workspace
     * state, ...) must not force a cache miss for every cached answer that used this
     * document as context. ContentHashService/AnswerCacheService are optional
     * (nullable, setter-injected like eventDispatcher above) so indexer tests that
     * don't need this side effect don't have to wire it up.
     *
     * The return value also feeds SearchDocumentInput::$contentChanged
     * (SPEC-v3-caching-chunking.md Phase B) — the same hash comparison that gates
     * cache invalidation also tells SearchBackendInterface implementations whether
     * re-embedding this document is necessary. When the services aren't injected at
     * all, this returns true (pre-Phase-B behaviour: always treat as changed, so
     * embedding is never silently skipped just because hash tracking isn't wired up).
     */
    private function trackContentChange(SearchDocumentInput $document): bool
    {
        if ($this->contentHashService === null || $this->answerCacheService === null) {
            return true;
        }

        $newHash = $this->contentHashService->computeContentHash($document->content);
        if (!$this->contentHashService->hasContentChanged($document->type, $document->uid, $newHash)) {
            return false;
        }

        $this->contentHashService->setContentHash($document->type, $document->uid, $newHash);
        $this->answerCacheService->invalidateForUrl($document->url);

        return true;
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
        $this->contentHashService?->removeContentHash($this->getType(), $uid);
        $this->chunkIndexingService?->removeChunks($this->getType(), $uid);

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

    /**
     * Resolves the page id to target when generating an Extbase plugin detail
     * URL from a background/CLI context — an indexer has no "current page" to
     * infer from the way a real frontend request does, and the record's own
     * storage folder ($record->getPid()) is essentially never the page that
     * actually renders the plugin, so it can't be used directly either.
     *
     * Reads the first page from the named Extbase route enhancer's
     * `limitToPages` (config/sites/{site}/config.yaml) instead of a
     * hardcoded page UID, so this keeps working if the page tree changes or
     * this indexer is reused on a different site copy.
     *
     * A `limitToPages` entry may itself be a page *translation* row (its own
     * uid, distinct from the default-language page) — PageRouter::generateUri()
     * expects the default-language uid for its page argument; passing a
     * translation's own uid produces a URL combining that translation's slug
     * with a record slug resolved without any language filtering, which
     * matches no real route. Resolved back to the default-language page here
     * so callers never have to think about it.
     *
     * @return int The resolved page id, or $fallbackPageId if no matching
     *   route enhancer is configured (e.g. running against an environment
     *   where it hasn't been set up) — buildUrl() implementations should
     *   already be falling back gracefully in that case, same as before this
     *   existed.
     */
    protected function resolvePluginTargetPageId(
        Site $site,
        string $extensionName,
        string $pluginName,
        int $fallbackPageId,
    ): int {
        foreach ($site->getConfiguration()['routeEnhancers'] ?? [] as $enhancer) {
            if (($enhancer['extension'] ?? null) !== $extensionName || ($enhancer['plugin'] ?? null) !== $pluginName) {
                continue;
            }

            $limitToPages = $enhancer['limitToPages'] ?? [];
            $firstPageId = reset($limitToPages);

            if ($firstPageId === false) {
                break;
            }

            return $this->resolveDefaultLanguagePageId((int) $firstPageId);
        }

        return $fallbackPageId;
    }

    private function resolveDefaultLanguagePageId(int $pageId): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('sys_language_uid', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return $pageId;
        }

        if ((int) $row['sys_language_uid'] > 0 && (int) $row['l10n_parent'] > 0) {
            return (int) $row['l10n_parent'];
        }

        return $pageId;
    }

    abstract protected function buildContent(object $record): string;

    abstract protected function buildUrl(object $record): string;

    abstract protected function getRecordsForIndexing(IndexingContext $context): iterable;
}
