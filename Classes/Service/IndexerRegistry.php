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
        foreach ($this->indexers as $indexer) {
            if ($indexer->supports($table)) {
                return $indexer;
            }
        }

        return null;
    }
}
