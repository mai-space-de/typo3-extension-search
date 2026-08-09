<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Detects and removes orphaned chunks from the search index.
 *
 * When a document is updated and its chunk count decreases (e.g., content shrinks),
 * the old chunks beyond the new count remain in the index as orphans. This service
 * identifies and removes them to keep the index clean and accurate.
 *
 * Not called from the live indexing flow: nothing writes to tx_maisearch_chunks yet
 * (ContentChunkerService's revival is explicitly still-deferred, SPEC-v2.md Phase 7
 * addendum) — wiring a cleanup call against a table nothing produces rows in would
 * always find zero rows. Kept correct and tested so it's ready once chunking exists.
 */
final class OrphanChunkCleanupService
{
    private const string TABLE = 'tx_maisearch_chunks';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param string $type Document type (e.g., 'page', 'news')
     * @param int $uid Document UID
     * @param int $currentChunkCount Current number of chunks for this document
     * @return int Number of orphaned chunks removed
     */
    public function cleanupOrphans(string $type, int $uid, int $currentChunkCount): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('document_type', $queryBuilder->createNamedParameter($type)),
                $queryBuilder->expr()->eq('document_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gt('chunk_index', $queryBuilder->createNamedParameter($currentChunkCount - 1, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * Get the current chunk count for a document.
     */
    public function getChunkCount(string $type, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('document_type', $queryBuilder->createNamedParameter($type)),
                $queryBuilder->expr()->eq('document_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return (int) $result;
    }

    /**
     * @return int Number of chunks removed
     */
    public function removeAllChunks(string $type, int $uid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('document_type', $queryBuilder->createNamedParameter($type)),
                $queryBuilder->expr()->eq('document_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }
}
