<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Event;

/**
 * Dispatched before a record is removed from the index.
 */
final class BeforeRecordRemovedEvent
{
    public function __construct(
        public readonly string $indexerType,
        public readonly string $tableName,
        public readonly int $recordUid,
    ) {}
}
