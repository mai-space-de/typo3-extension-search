<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Service;

use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use TYPO3\CMS\Core\SingletonInterface;

class IndexerRegistry implements SingletonInterface
{
    private array $indexers = [];

    public function addIndexer(SearchIndexerInterface $indexer): void
    {
        $this->indexers[$indexer->getType()] = $indexer;
    }

    public function getIndexer(string $type): ?SearchIndexerInterface
    {
        return $this->indexers[$type] ?? null;
    }

    /**
     * @return SearchIndexerInterface[]
     */
    public function getAll(): array
    {
        return array_values($this->indexers);
    }

    public function getIndexerForTable(string $table): ?SearchIndexerInterface
    {
        $matching = $this->getIndexersForTable($table);

        return $matching[0] ?? null;
    }

    /**
     * All indexers that claim support for $table.
     *
     * Multiple indexers may share a table (e.g. PageIndexer + NewsIndexer +
     * EventIndexer all support `pages` after the custom-doktype cutover).
     * Queue processing must run every match so typed documents stay in sync.
     *
     * @return list<SearchIndexerInterface>
     */
    public function getIndexersForTable(string $table): array
    {
        $matching = [];
        foreach ($this->indexers as $indexer) {
            if ($indexer->supports($table)) {
                $matching[] = $indexer;
            }
        }

        return $matching;
    }
}
