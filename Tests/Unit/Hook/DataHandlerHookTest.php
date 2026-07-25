<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Hook;

use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Hook\DataHandlerHook;
use Maispace\MaiSearch\Service\IndexerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class DataHandlerHookTest extends TestCase
{
    private FlashMessageService&MockObject $flashMessageService;

    private FlashMessageQueue&MockObject $flashMessageQueue;

    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->flashMessageService = $this->createMock(FlashMessageService::class);
        $this->flashMessageService
            ->method('getMessageQueueByIdentifier')
            ->willReturn($this->flashMessageQueue);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function buildHook(IndexerRegistry $registry): DataHandlerHook
    {
        return new DataHandlerHook(
            $registry,
            $this->flashMessageService,
            $this->logger,
        );
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

    #[Test]
    public function processCmdmapDeleteSurfacesSolrFailureAsFlashMessage(): void
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer
            ->expects(self::once())
            ->method('removeRecord')
            ->willThrowException(new \RuntimeException('Solr HTTP error: Could not resolve host: solr (6)'));

        $this->logger
            ->expects(self::once())
            ->method('warning')
            ->with(
                'Solr {operation} failed for {table}:{uid}: {message}',
                self::callback(static function (array $context): bool {
                    return $context['operation'] === 'remove'
                        && $context['table'] === 'pages'
                        && $context['uid'] === 99
                        && str_contains((string) $context['message'], 'Could not resolve host');
                }),
            );

        $this->flashMessageQueue
            ->expects(self::once())
            ->method('enqueue')
            ->with(self::callback(static function (FlashMessage $message): bool {
                return $message->getSeverity() === ContextualFeedbackSeverity::WARNING
                    && str_contains($message->getMessage(), 'pages')
                    && str_contains($message->getMessage(), '99');
            }));

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
