<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class IndexQueueRepositoryTest extends TestCase
{
    private const string TABLE = 'tx_maisearch_index_queue';

    private ConnectionPool&MockObject $connectionPool;

    private IndexQueueRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->subject = new IndexQueueRepository($this->connectionPool);
    }

    /**
     * @return QueryBuilder&MockObject
     */
    private function createQueryBuilderMock(): QueryBuilder
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('status = :dcValue1');
        $expr->method('in')->willReturn('uid IN (:dcValue1)');
        $queryBuilder->method('expr')->willReturn($expr);

        return $queryBuilder;
    }

    // ─── enqueue ────────────────────────────────────────────────────────────

    #[Test]
    public function enqueueDeletesExistingPendingRowThenInserts(): void
    {
        $deleteQueryBuilder = $this->createQueryBuilderMock();
        $deleteQueryBuilder->expects(self::once())->method('delete')->with(self::TABLE)->willReturnSelf();
        $deleteQueryBuilder->method('executeStatement')->willReturn(1);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($deleteQueryBuilder);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                self::TABLE,
                self::callback(static function (array $data): bool {
                    self::assertSame('pages', $data['table_name']);
                    self::assertSame(42, $data['record_uid']);
                    self::assertSame('index', $data['operation']);
                    self::assertSame('pending', $data['status']);
                    self::assertSame(0, $data['processed_date']);
                    return true;
                }),
            );

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with(self::TABLE)
            ->willReturn($connection);

        $this->subject->enqueue('pages', 42, IndexQueueRepository::OPERATION_INDEX);
    }

    // ─── findOpenBatch ──────────────────────────────────────────────────────

    #[Test]
    public function findOpenBatchReturnsPendingRowsOrderedByQueuedDate(): void
    {
        $rows = [
            ['uid' => 1, 'table_name' => 'pages', 'record_uid' => 10],
            ['uid' => 2, 'table_name' => 'pages', 'record_uid' => 11],
        ];

        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::once())->method('orderBy')->with('queued_date', 'ASC')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('setMaxResults')->with(20)->willReturnSelf();

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame($rows, $this->subject->findOpenBatch(20));
    }

    // ─── markProcessing ─────────────────────────────────────────────────────

    #[Test]
    public function markProcessingDoesNothingForEmptyArray(): void
    {
        $this->connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $this->subject->markProcessing([]);
    }

    #[Test]
    public function markProcessingUpdatesStatusForGivenUids(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $queryBuilder->expects(self::once())->method('update')->with(self::TABLE)->willReturnSelf();
        $queryBuilder->expects(self::once())->method('set')->with('status', IndexQueueRepository::STATUS_PROCESSING)->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn(2);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        $this->subject->markProcessing([1, 2]);
    }

    // ─── markDone / markFailed ──────────────────────────────────────────────

    #[Test]
    public function markDoneUpdatesStatusAndClearsErrorMessage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('update')
            ->with(
                self::TABLE,
                self::callback(static function (array $data): bool {
                    self::assertSame('done', $data['status']);
                    self::assertSame('', $data['error_message']);
                    return true;
                }),
                ['uid' => 5],
            );

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with(self::TABLE)
            ->willReturn($connection);

        $this->subject->markDone(5);
    }

    #[Test]
    public function markFailedUpdatesStatusAndStoresErrorMessage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('update')
            ->with(
                self::TABLE,
                self::callback(static function (array $data): bool {
                    self::assertSame('failed', $data['status']);
                    self::assertSame('boom', $data['error_message']);
                    return true;
                }),
                ['uid' => 5],
            );

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with(self::TABLE)
            ->willReturn($connection);

        $this->subject->markFailed(5, 'boom');
    }

    // ─── countByStatus ──────────────────────────────────────────────────────

    #[Test]
    public function countByStatusReturnsAllFourBuckets(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('3');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame(
            ['pending' => 3, 'processing' => 3, 'done' => 3, 'failed' => 3],
            $this->subject->countByStatus(),
        );
    }

    // ─── findRecent ─────────────────────────────────────────────────────────

    #[Test]
    public function findRecentOrdersByUidDescending(): void
    {
        $rows = [['uid' => 9], ['uid' => 8]];

        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::once())->method('orderBy')->with('uid', 'DESC')->willReturnSelf();

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame($rows, $this->subject->findRecent());
    }

    // ─── cleanupOld ─────────────────────────────────────────────────────────

    #[Test]
    public function cleanupOldReturnsZeroWhenNothingToDelete(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::never())->method('delete');

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame(0, $this->subject->cleanupOld());
    }

    #[Test]
    public function cleanupOldDeletesFoundRows(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([1, 2, 3]);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $queryBuilder->expects(self::once())->method('delete')->with(self::TABLE)->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn(3);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($queryBuilder);

        self::assertSame(3, $this->subject->cleanupOld());
    }
}
