<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Event;

use Maispace\MaiSearch\Domain\Model\IndexingContext;

/**
 * Dispatched before an indexer builds a SearchDocumentInput from a record.
 *
 * Listeners can inspect the record and context, but cannot modify the document yet
 * (it hasn't been built). Use AfterDocumentBuiltEvent to mutate the document.
 */
final class BeforeDocumentBuiltEvent
{
    public function __construct(
        public readonly object $record,
        public readonly IndexingContext $context,
        public readonly string $indexerType,
    ) {}
}
