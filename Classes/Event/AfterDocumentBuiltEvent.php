<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Event;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;

/**
 * Dispatched after an indexer builds a SearchDocumentInput, before it's sent to the backend.
 *
 * Listeners can mutate the document (add fields, modify content, etc.) before indexing.
 */
final class AfterDocumentBuiltEvent
{
    public function __construct(
        public SearchDocumentInput $document,
        public readonly object $record,
        public readonly IndexingContext $context,
        public readonly string $indexerType,
    ) {}
}
