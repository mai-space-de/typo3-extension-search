<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Event\AfterRecordIndexedEvent;
use Maispace\MaiSearch\Event\BeforeRecordIndexedEvent;
use Maispace\MaiSearch\Event\BeforeRecordRemovedEvent;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Drains the async index queue (tx_maisearch_index_queue) that
 * DataHandlerHook populates. Run via IndexQueueProcessingTask (a scheduler
 * task) or the backend Queue view's manual "process now" trigger.
 *
 * Record fetching and language resolution are done here, at process time,
 * rather than at enqueue time — the record may have changed again (or been
 * deleted) between being queued and being processed, so re-fetching the
 * current row is the only way to index what's actually there. If the record
 * has since vanished, the job degrades to a removal rather than failing.
 */
class IndexQueueProcessor implements LoggerAwareInterface, SingletonInterface
{
    use LoggerAwareTrait;

    private const int DEFAULT_BATCH_SIZE = 50;

    public function __construct(
        private readonly IndexQueueRepository $indexQueueRepository,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @return int Number of jobs picked up in this run
     */
    public function processBatch(int $batchSize = self::DEFAULT_BATCH_SIZE): int
    {
        $jobs = $this->indexQueueRepository->findOpenBatch($batchSize);

        if ($jobs !== []) {
            $uids = array_map(static fn(array $job): int => (int) $job['uid'], $jobs);
            $this->indexQueueRepository->markProcessing($uids);

            foreach ($jobs as $job) {
                $this->processJob($job);
            }
        }

        // Every reindex (or repeated editor save) enqueues fresh jobs for the same
        // (table, uid) without touching previously done/failed rows for it — see
        // IndexQueueRepository::enqueue()'s docblock: that history is kept
        // deliberately, for the backend Queue view's "Recent Queue Activity" and
        // failure diagnostics. Left entirely unpruned, that history grows forever,
        // so the module's "done" counter becomes a lifetime total across every past
        // run instead of reflecting the current queue — pruned here, once per batch,
        // so it stays bounded no matter how this method ends up being invoked
        // (scheduler tick or the backend module's "Process Queue Now" button).
        $this->indexQueueRepository->cleanupOld();

        return count($jobs);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function processJob(array $job): void
    {
        $uid = (int) $job['uid'];
        $table = (string) $job['table_name'];
        $recordUid = (int) $job['record_uid'];
        $operation = (string) $job['operation'];

        $indexer = $this->indexerRegistry->getIndexerForTable($table);
        if ($indexer === null) {
            $this->indexQueueRepository->markFailed(
                $uid,
                sprintf('No indexer registered for table "%s"', $table),
            );

            return;
        }

        try {
            if ($operation === IndexQueueRepository::OPERATION_REMOVE) {
                $this->eventDispatcher->dispatch(
                    new BeforeRecordRemovedEvent($indexer->getType(), $table, $recordUid),
                );
                $indexer->removeRecord($recordUid, $table);
            } else {
                $this->indexRecord($indexer, $table, $recordUid);
            }

            $this->indexQueueRepository->markDone($uid);
        } catch (\Throwable $exception) {
            $this->logger?->warning(
                'Index queue job #{uid} ({operation} {table}:{recordUid}) failed: {message}',
                [
                    'uid' => $uid,
                    'operation' => $operation,
                    'table' => $table,
                    'recordUid' => $recordUid,
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ],
            );

            $this->indexQueueRepository->markFailed($uid, $exception->getMessage());
        }
    }

    private function indexRecord(SearchIndexerInterface $indexer, string $table, int $recordUid): void
    {
        $record = $this->fetchRecord($table, $recordUid);

        if ($record === null) {
            // The record is gone by the time we got to it — nothing to index.
            $indexer->removeRecord($recordUid, $table);

            return;
        }

        $languageUid = isset($record->sys_language_uid) ? (int) $record->sys_language_uid : 0;
        $context = new IndexingContext(
            core: '',
            languageCode: $this->resolveLanguageCode($languageUid),
            languageUid: $languageUid,
        );

        $this->eventDispatcher->dispatch(
            new BeforeRecordIndexedEvent($record, $context, $indexer->getType(), $table, $recordUid),
        );

        $indexer->indexRecord($record, $context);

        $this->eventDispatcher->dispatch(
            new AfterRecordIndexedEvent($record, $context, $indexer->getType(), $table, $recordUid),
        );
    }

    /**
     * Fetch a single record as \stdClass (without TYPO3 restriction overlay so that
     * hidden/deleted records are still re-indexed correctly).
     */
    private function fetchRecord(string $table, int $uid): ?\stdClass
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $obj = new \stdClass();
        foreach ($row as $key => $value) {
            $obj->$key = $value;
        }

        return $obj;
    }

    /**
     * Resolve the ISO 639-1 code for a sys_language_uid via the site's configured languages,
     * so queue processing routes to the same core a full reindex would use.
     */
    private function resolveLanguageCode(int $languageUid): ?string
    {
        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            return null;
        }

        $site = reset($sites);

        if ($site === false) {
            return null;
        }

        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() === $languageUid) {
                return $language->getLocale()->getLanguageCode();
            }
        }

        return null;
    }
}
