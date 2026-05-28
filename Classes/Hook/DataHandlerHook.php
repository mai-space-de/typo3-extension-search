<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Hook;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Service\IndexerRegistry;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook for incremental Solr index updates.
 *
 * Hooks:
 *   processDatamap_afterDatabaseOperations — re-index the record after save.
 *   processCmdmap_deleteAction             — remove the document from Solr on delete.
 */
class DataHandlerHook
{
    public function __construct(private readonly IndexerRegistry $indexerRegistry) {}

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
        $indexer = $this->indexerRegistry->getIndexerForTable($table);
        if ($indexer === null) {
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

        $record = $this->fetchRecord($table, $uid);
        if ($record === null) {
            return;
        }

        $indexer->indexRecord($record, new IndexingContext(core: ''));
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
        $indexer = $this->indexerRegistry->getIndexerForTable($table);
        if ($indexer === null) {
            return;
        }

        $indexer->removeRecord($id, $table);
    }

    /**
     * Fetch a single record as \stdClass (without TYPO3 restriction overlay so that
     * hidden/deleted records are still re-indexed correctly from the DataHandler context).
     */
    private function fetchRecord(string $table, int $uid): ?\stdClass
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);

        // Remove default restrictions — DataHandler already applied access checks.
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
}
