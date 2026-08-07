<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Tracks content hashes for indexed documents to enable cache invalidation.
 *
 * When a document is indexed, its content hash is stored. On subsequent updates,
 * the hash is compared to detect actual content changes. This prevents unnecessary
 * cache invalidations when only metadata (timestamps, etc.) changes.
 */
final class ContentHashService
{
    private const string TABLE = 'tx_maisearch_content_hash';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Get the stored content hash for a document.
     */
    public function getContentHash(string $type, int $uid): ?string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $result = $queryBuilder
            ->select('content_hash')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('document_type', $queryBuilder->createNamedParameter($type)),
                $queryBuilder->expr()->eq('document_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return $result !== false ? (string) $result : null;
    }

    /**
     * Store or update the content hash for a document.
     */
    public function setContentHash(string $type, int $uid, string $contentHash): void
    {
        $existingHash = $this->getContentHash($type, $uid);

        if ($existingHash === null) {
            $this->insertContentHash($type, $uid, $contentHash);
        } else {
            $this->updateContentHash($type, $uid, $contentHash);
        }
    }

    /**
     * Check if the content has actually changed.
     */
    public function hasContentChanged(string $type, int $uid, string $newContentHash): bool
    {
        $existingHash = $this->getContentHash($type, $uid);

        return $existingHash === null || $existingHash !== $newContentHash;
    }

    public function removeContentHash(string $type, int $uid): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('document_type', $queryBuilder->createNamedParameter($type)),
                $queryBuilder->expr()->eq('document_uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * Compute a content hash from the document content.
     */
    public function computeContentHash(string $content): string
    {
        return hash('sha256', $content);
    }

    private function insertContentHash(string $type, int $uid, string $contentHash): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, [
            'document_type' => $type,
            'document_uid' => $uid,
            'content_hash' => $contentHash,
            'tstamp' => time(),
        ]);
    }

    private function updateContentHash(string $type, int $uid, string $contentHash): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->update(
            self::TABLE,
            [
                'content_hash' => $contentHash,
                'tstamp' => time(),
            ],
            [
                'document_type' => $type,
                'document_uid' => $uid,
            ],
        );
    }
}
