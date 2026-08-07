<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Domain\Service;

use Maispace\MaiSearch\Domain\Model\IndexingContext;

interface SearchIndexerInterface
{
    public function getType(): string;

    public function indexAll(IndexingContext $context): void;

    public function indexRecord(object $record, IndexingContext $context): void;

    public function removeRecord(int $uid, string $table): void;

    public function getBoost(string $type): float;

    public function supports(string $table): bool;

    /**
     * Return the primary table name this indexer handles.
     * Used by FullReindexTask to repopulate the queue.
     */
    public function getTableName(): string;

    /**
     * Return all record UIDs that should be indexed for the given context.
     * Used by FullReindexTask to repopulate the queue instead of indexing directly.
     *
     * @return iterable<int> Record UIDs
     */
    public function getRecordUidsForReindex(IndexingContext $context): iterable;
}
