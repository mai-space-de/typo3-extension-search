<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Hook;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Hook\DataHandlerHook;
use Maispace\MaiSearch\Service\IndexerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class DataHandlerHookTest extends TestCase
{
    private function buildHook(IndexerRegistry $registry): DataHandlerHook
    {
        return new DataHandlerHook($registry);
    }

    private function makeRegistry(?SearchIndexerInterface $indexer = null): IndexerRegistry
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('getIndexerForTable')->willReturn($indexer);

        return $registry;
    }

    private function makeDataHandler(array $substMap = []): DataHandler
    {
        $handler = $this->createMock(DataHandler::class);
        $handler->substNEWwithIDs = $substMap;

        return $handler;
    }

    #[Test]
    public function processDatamapDoesNothingWhenNoIndexerForTable(): void
    {
        $registry = $this->makeRegistry(null);
        $hook = $this->buildHook($registry);

        $hook->processDatamap_afterDatabaseOperations(
            'update',
            'tx_unknown_table',
            42,
            [],
            $this->makeDataHandler(),
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function processDatamapDoesNothingForZeroUid(): void
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects(self::never())->method('indexRecord');

        $registry = $this->makeRegistry($indexer);
        $hook = $this->buildHook($registry);

        $hook->processDatamap_afterDatabaseOperations(
            'update',
            'pages',
            0,
            [],
            $this->makeDataHandler(),
        );
    }

    #[Test]
    public function processDatamapResolvesNewRecordUidFromSubstMap(): void
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects(self::never())->method('indexRecord');

        $registry = $this->makeRegistry($indexer);
        $hook = $this->buildHook($registry);

        $hook->processDatamap_afterDatabaseOperations(
            'new',
            'pages',
            'NEW1234',
            [],
            $this->makeDataHandler(['NEW1234' => 0]),
        );
    }

    #[Test]
    public function processCmdmapDeleteDoesNothingWhenNoIndexerForTable(): void
    {
        $registry = $this->makeRegistry(null);
        $hook = $this->buildHook($registry);

        $deleted = false;
        $hook->processCmdmap_deleteAction(
            'tx_unknown_table',
            99,
            [],
            $deleted,
            $this->makeDataHandler(),
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function processCmdmapDeleteCallsRemoveRecord(): void
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects(self::once())->method('removeRecord')->with(99, 'pages');

        $registry = $this->makeRegistry($indexer);
        $hook = $this->buildHook($registry);

        $deleted = false;
        $hook->processCmdmap_deleteAction(
            'pages',
            99,
            [],
            $deleted,
            $this->makeDataHandler(),
        );
    }
}
