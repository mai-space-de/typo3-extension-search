<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Hook;

use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Hook\DataHandlerHook;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class DataHandlerHookTest extends TestCase
{
    private IndexQueueRepository&MockObject $indexQueueRepository;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexQueueRepository = $this->createMock(IndexQueueRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function buildHook(IndexerRegistry $registry): DataHandlerHook
    {
        return new DataHandlerHook($registry, $this->indexQueueRepository, $this->logger);
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

    // ─── processDatamap_afterDatabaseOperations ────────────────────────────

    #[Test]
    public function processDatamapDoesNothingWhenNoIndexerForTable(): void
    {
        $this->indexQueueRepository->expects(self::never())->method('enqueue');

        $registry = $this->makeRegistry(null);
        $hook = $this->buildHook($registry);

        $hook->processDatamap_afterDatabaseOperations(
            'update',
            'tx_unknown_table',
            42,
            [],
            $this->makeDataHandler(),
        );
    }

    #[Test]
    public function processDatamapDoesNothingForZeroUid(): void
    {
        $this->indexQueueRepository->expects(self::never())->method('enqueue');

        $indexer = $this->createMock(SearchIndexerInterface::class);
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
    public function processDatamapDoesNothingWhenNewRecordUidIsUnresolvable(): void
    {
        $this->indexQueueRepository->expects(self::never())->method('enqueue');

        $indexer = $this->createMock(SearchIndexerInterface::class);
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
    public function processDatamapEnqueuesAnIndexJobForAnUpdatedRecord(): void
    {
        $this->indexQueueRepository
            ->expects(self::once())
            ->method('enqueue')
            ->with('pages', 42, IndexQueueRepository::OPERATION_INDEX);

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $registry = $this->makeRegistry($indexer);
        $hook = $this->buildHook($registry);

        $hook->processDatamap_afterDatabaseOperations(
            'update',
            'pages',
            42,
            [],
            $this->makeDataHandler(),
        );
    }

    #[Test]
    public function processDatamapResolvesNewRecordUidFromSubstMapAndEnqueues(): void
    {
        $this->indexQueueRepository
            ->expects(self::once())
            ->method('enqueue')
            ->with('pages', 55, IndexQueueRepository::OPERATION_INDEX);

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $registry = $this->makeRegistry($indexer);
        $hook = $this->buildHook($registry);

        $hook->processDatamap_afterDatabaseOperations(
            'new',
            'pages',
            'NEW1234',
            [],
            $this->makeDataHandler(['NEW1234' => 55]),
        );
    }

    #[Test]
    public function processDatamapLogsWarningWhenEnqueueingThrows(): void
    {
        $this->indexQueueRepository
            ->method('enqueue')
            ->willThrowException(new \RuntimeException('DB is gone'));

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Could not enqueue {operation} job for {table}:{uid}: {message}',
                self::callback(static function (array $context): bool {
                    return $context['operation'] === 'index'
                        && $context['table'] === 'pages'
                        && $context['uid'] === 42
                        && str_contains((string) $context['message'], 'DB is gone');
                }),
            );

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $registry = $this->makeRegistry($indexer);
        $hook = $this->buildHook($registry);

        $hook->processDatamap_afterDatabaseOperations(
            'update',
            'pages',
            42,
            [],
            $this->makeDataHandler(),
        );
    }

    // ─── processCmdmap_deleteAction ─────────────────────────────────────────

    #[Test]
    public function processCmdmapDeleteDoesNothingWhenNoIndexerForTable(): void
    {
        $this->indexQueueRepository->expects(self::never())->method('enqueue');

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
    }

    #[Test]
    public function processCmdmapDeleteEnqueuesARemoveJob(): void
    {
        $this->indexQueueRepository
            ->expects(self::once())
            ->method('enqueue')
            ->with('pages', 99, IndexQueueRepository::OPERATION_REMOVE);

        $indexer = $this->createMock(SearchIndexerInterface::class);
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

    #[Test]
    public function processCmdmapDeleteLogsWarningWhenEnqueueingThrows(): void
    {
        $this->indexQueueRepository
            ->method('enqueue')
            ->willThrowException(new \RuntimeException('DB is gone'));

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Could not enqueue {operation} job for {table}:{uid}: {message}',
                self::callback(static function (array $context): bool {
                    return $context['operation'] === 'remove'
                        && $context['table'] === 'pages'
                        && $context['uid'] === 99;
                }),
            );

        $indexer = $this->createMock(SearchIndexerInterface::class);
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
