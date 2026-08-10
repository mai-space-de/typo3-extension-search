<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Persists the async indexing queue in tx_maisearch_index_queue.
 *
 * Replaces DataHandlerHook's previous synchronous indexRecord()/removeRecord()
 * calls: the hook now only enqueues (table, uid, operation), and
 * IndexQueueProcessor (run via IndexQueueProcessingTask, a scheduler task)
 * drains the queue in batches. This decouples a page save from Solr/Meilisearch
 * latency or downtime, and gives failures a durable, admin-visible home (the
 * backend Queue view) instead of a one-off flash message the editor may never
 * see.
 *
 * @see \Maispace\MaiSearch\Domain\Service\IndexQueueProcessor
 * @see \Maispace\MaiSearch\Scheduler\IndexQueueProcessingTask
 */
class IndexQueueRepository
{
    private const string TABLE = 'tx_maisearch_index_queue';

    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PROCESSING = 'processing';
    public const string STATUS_DONE = 'done';
    public const string STATUS_FAILED = 'failed';

    public const string OPERATION_INDEX = 'index';
    public const string OPERATION_REMOVE = 'remove';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Enqueue a job for (table, uid). Any existing *pending* job for the same
     * (table, uid) is replaced rather than duplicated — a record saved twice in
     * quick succession only needs to be indexed once with the latest operation,
     * and a delete queued after an index (or vice versa) must supersede it, not
     * queue alongside it. Jobs already picked up (processing/done/failed) are
     * left alone; a fresh save always gets a fresh job.
     */
    public function enqueue(string $table, int $uid, string $operation): void
    {
        $this->deletePendingFor($table, $uid);

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'table_name' => $table,
            'record_uid' => $uid,
            'operation' => $operation,
            'status' => self::STATUS_PENDING,
            'error_message' => '',
            'queued_date' => time(),
            'processed_date' => 0,
        ]);
    }

    /**
     * Deletes every row regardless of status — called at the start of a full
     * reindex (IndexManagementService::repopulateQueue()) so a fresh reindex
     * supersedes whatever was queued by every previous one. Without this, the
     * backend module's "jobs in queue" and per-status counts read as a lifetime
     * total across every reindex ever run instead of the progress of the one
     * just kicked off — clicking "Alles neu indexieren" now defines a clean
     * total, and clicking "Jetzt abarbeiten" any number of times only ever
     * moves jobs between pending/processing/done/failed within that total.
     */
    public function clearAll(): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->truncate(self::TABLE);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOpenBatch(int $limit): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq(
                'status',
                $queryBuilder->createNamedParameter(self::STATUS_PENDING),
            ))
            ->orderBy('queued_date', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param array<int, int> $uids
     */
    public function markProcessing(array $uids): void
    {
        $this->updateStatus($uids, self::STATUS_PROCESSING);
    }

    public function markDone(int $uid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            ['status' => self::STATUS_DONE, 'processed_date' => time(), 'error_message' => ''],
            ['uid' => $uid],
        );
    }

    public function markFailed(int $uid, string $errorMessage): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            ['status' => self::STATUS_FAILED, 'processed_date' => time(), 'error_message' => $errorMessage],
            ['uid' => $uid],
        );
    }

    /**
     * @return array{pending: int, processing: int, done: int, failed: int}
     */
    public function countByStatus(): array
    {
        $counts = [
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_DONE => 0,
            self::STATUS_FAILED => 0,
        ];

        foreach (array_keys($counts) as $status) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $counts[$status] = (int) $queryBuilder
                ->count('uid')
                ->from(self::TABLE)
                ->where($queryBuilder->expr()->eq(
                    'status',
                    $queryBuilder->createNamedParameter($status),
                ))
                ->executeQuery()
                ->fetchOne();
        }

        return $counts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findRecent(int $limit = 50): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Delete already-processed entries (done/failed) beyond the most recent
     * $keep, oldest first.
     *
     * Called on every queue-processing run (IndexQueueProcessor::processBatch())
     * so the table doesn't grow forever across repeated reindexes/editor saves.
     * Bounded by a *retained* count rather than a per-call delete limit: an
     * earlier version took a $limit of rows to delete per call, which — once the
     * total backlog shrank below that limit — deleted the entire table on every
     * single call, including jobs that had only just been marked done in the
     * same run. Keeping the $keep most recent rows instead means there's always
     * something left for the backend module's "Recent Queue Activity" view and
     * failure diagnostics, no matter how small the backlog is when this runs.
     *
     * @return int Number of deleted rows
     */
    public function cleanupOld(int $keep = 200): int
    {
        $countQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $total = (int) $countQueryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where($countQueryBuilder->expr()->in(
                'status',
                $countQueryBuilder->createNamedParameter([self::STATUS_DONE, self::STATUS_FAILED], ArrayParameterType::STRING),
            ))
            ->executeQuery()
            ->fetchOne();

        if ($total <= $keep) {
            return 0;
        }

        $selectQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $uids = $selectQueryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where($selectQueryBuilder->expr()->in(
                'status',
                $selectQueryBuilder->createNamedParameter([self::STATUS_DONE, self::STATUS_FAILED], ArrayParameterType::STRING),
            ))
            ->orderBy('processed_date', 'ASC')
            ->setMaxResults($total - $keep)
            ->executeQuery()
            ->fetchFirstColumn();

        if ($uids === []) {
            return 0;
        }

        $deleteQueryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);

        return $deleteQueryBuilder
            ->delete(self::TABLE)
            ->where(
                $deleteQueryBuilder->expr()->in(
                    'uid',
                    $deleteQueryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER),
                ),
            )
            ->executeStatement();
    }

    private function deletePendingFor(string $table, int $uid): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('table_name', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(self::STATUS_PENDING)),
            )
            ->executeStatement();
    }

    /**
     * @param array<int, int> $uids
     */
    private function updateStatus(array $uids, string $status): void
    {
        if ($uids === []) {
            return;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->update(self::TABLE)
            ->set('status', $status)
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER)),
            )
            ->executeStatement();
    }
}
