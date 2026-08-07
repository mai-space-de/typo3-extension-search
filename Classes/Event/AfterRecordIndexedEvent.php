<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Event;

use Maispace\MaiSearch\Domain\Model\IndexingContext;

/**
 * Dispatched after a record has been successfully indexed.
 */
final class AfterRecordIndexedEvent
{
    public function __construct(
        public readonly object $record,
        public readonly IndexingContext $context,
        public readonly string $indexerType,
        public readonly string $tableName,
        public readonly int $recordUid,
    ) {}
}
