<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Repository for tx_maisearch_chunks: per-chunk content-hash bookkeeping
 * (SPEC-v3-caching-chunking.md Phase D) plus orphan detection/removal.
 *
 * Mirrors ContentHashService's role for whole documents, one level down — each
 * row tracks one chunk of one document, so ChunkIndexingService can skip
 * re-embedding a chunk whose hash hasn't changed, and detect which trailing
 * chunk indices need removing when a document shrinks (e.g. content shortened
 * from 5 chunks to 3 leaves indices 3-4 as orphans, both here and in the
 * search backend).
 */
class OrphanChunkCleanupService
{
    private const string TABLE = 'tx_maisearch_chunks';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Get the stored hash for one chunk, or null if this chunk index has never
     * been indexed for this document.
     */
    public function getChunkHash(string $type, int $uid, int $chunkIndex): ?string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->select('chunk_hash')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('document_type', $queryBuilder->createNamedParameter($type)),
                $queryBuilder->expr()->eq('document_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('chunk_index', $queryBuilder->createNamedParameter($chunkIndex, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return $result !== false ? (string) $result : null;
    }

    public function hasChunkChanged(string $type, int $uid, int $chunkIndex, string $newHash): bool
    {
        return $this->getChunkHash($type, $uid, $chunkIndex) !== $newHash;
    }

    /**
     * Store or update the hash (and text, for local diffing without a backend
     * round-trip) for one chunk.
     */
    public function setChunkHash(string $type, int $uid, int $chunkIndex, string $hash, string $content): void
    {
        $existingHash = $this->getChunkHash($type, $uid, $chunkIndex);
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        if ($existingHash === null) {
            $connection->insert(self::TABLE, [
                'document_type' => $type,
                'document_uid' => $uid,
                'chunk_index' => $chunkIndex,
                'chunk_content' => $content,
                'chunk_hash' => $hash,
                'tstamp' => time(),
            ]);

            return;
        }

        $connection->update(
            self::TABLE,
            [
                'chunk_content' => $content,
                'chunk_hash' => $hash,
                'tstamp' => time(),
            ],
            [
                'document_type' => $type,
                'document_uid' => $uid,
                'chunk_index' => $chunkIndex,
            ],
        );
    }

    /**
     * Chunk indices currently stored for this document at or beyond
     * $currentChunkCount — the ones cleanupOrphans() is about to delete locally,
     * needed by the caller first to also remove the matching documents from the
     * active search backend (ChunkSearchBackendInterface::removeChunk()).
     *
     * @return list<int>
     */
    public function findOrphanChunkIndices(string $type, int $uid, int $currentChunkCount): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $indices = $queryBuilder
            ->select('chunk_index')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('document_type', $queryBuilder->createNamedParameter($type)),
                $queryBuilder->expr()->eq('document_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('chunk_index', $queryBuilder->createNamedParameter($currentChunkCount, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn(mixed $index): int => (int) $index, $indices);
    }

    /**
     * Every document UID that currently has at least one chunk tracked for this
     * type — the candidate set `mai-search:cleanup-chunks`
     * (SPEC-v3-caching-chunking.md Phase F) diffs against an indexer's own live
     * record UIDs to find records deleted outside the normal DataHandlerHook path
     * (hard SQL deletes, disabled extensions, migration errors).
     *
     * @return list<int>
     */
    public function findDocumentUidsWithChunks(string $type): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $uids = $queryBuilder
            ->selectLiteral('DISTINCT document_uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('document_type', $queryBuilder->createNamedParameter($type)))
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn(mixed $uid): int => (int) $uid, $uids);
    }

    /**
     * Clears every tracked chunk hash — the chunk-level counterpart to
     * ContentHashService::removeAllHashes(), called from the same place
     * (IndexManagementService::clearIndex()/clearAllCores()) for the same
     * reason: a cleared backend has no chunks left, so local bookkeeping that
     * still says "this chunk is already indexed" must not survive the clear.
     */
    public function removeAllHashes(): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->truncate(self::TABLE);
    }

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
