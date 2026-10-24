<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Hook;

use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook for the async search index queue.
 *
 * Hooks:
 *   processDatamap_afterDatabaseOperations — enqueue an index job for the saved record.
 *   processCmdmap_deleteAction             — enqueue a removal job for the deleted record.
 *
 * Indexing itself no longer happens here (see IndexQueueProcessor, run via the
 * IndexQueueProcessingTask scheduler task): a page save must not block on
 * Solr/Meilisearch latency or downtime, and a real, durable, admin-visible
 * queue (the backend Queue view) is a better home for indexing failures than
 * a one-off flash message the editor may never see.
 */
class DataHandlerHook
{
    private readonly IndexerRegistry $indexerRegistry;

    private readonly IndexQueueRepository $indexQueueRepository;

    public function __construct(
        ?IndexerRegistry $indexerRegistry = null,
        ?IndexQueueRepository $indexQueueRepository = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->indexerRegistry = $indexerRegistry
            ?? GeneralUtility::makeInstance(IndexerRegistry::class);
        $this->indexQueueRepository = $indexQueueRepository
            ?? GeneralUtility::makeInstance(IndexQueueRepository::class);
    }

    /**
     * Called after a record was written to the database.
     *
     * @param string              $status   'new' or 'update'
     * @param string              $table    Table name
     * @param int|string          $id       Record UID (may be a "NEW…" string for fresh inserts)
     * @param array<string,mixed> $fieldArray Fields that were saved
     * @param DataHandler         $dataHandler
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($this->indexerRegistry->getIndexersForTable($table) === []) {
            return;
        }

        // Resolve the real UID for new records
        if ($status === 'new') {
            $uid = (int) ($dataHandler->substNEWwithIDs[$id] ?? 0);
        } else {
            $uid = (int) $id;
        }

        if ($uid <= 0) {
            return;
        }

        $this->enqueue($table, $uid, IndexQueueRepository::OPERATION_INDEX);
    }

    /**
     * Called before a record is deleted.
     *
     * @param string      $table
     * @param int         $id
     * @param array<string,mixed> $recordToDelete
     * @param bool        $recordWasDeleted (unused – passed by reference in TYPO3 core)
     * @param DataHandler $dataHandler
     */
    public function processCmdmap_deleteAction(
        string $table,
        int $id,
        array $recordToDelete,
        bool &$recordWasDeleted,
        DataHandler $dataHandler,
    ): void {
        if ($this->indexerRegistry->getIndexersForTable($table) === []) {
            return;
        }

        $this->enqueue($table, $id, IndexQueueRepository::OPERATION_REMOVE);
    }

    private function enqueue(string $table, int $uid, string $operation): void
    {
        try {
            $this->indexQueueRepository->enqueue($table, $uid, $operation);
        } catch (\Throwable $exception) {
            $this->logger?->warning(
                'Could not enqueue {operation} job for {table}:{uid}: {message}',
                [
                    'operation' => $operation,
                    'table' => $table,
                    'uid' => $uid,
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ],
            );
        }
    }
}
