<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Event;

use Maispace\MaiSearch\Domain\Model\IndexingContext;

/**
 * Dispatched before a record is indexed (after queue processing retrieves it).
 */
final class BeforeRecordIndexedEvent
{
    public function __construct(
        public readonly object $record,
        public readonly IndexingContext $context,
        public readonly string $indexerType,
        public readonly string $tableName,
        public readonly int $recordUid,
    ) {}
}
