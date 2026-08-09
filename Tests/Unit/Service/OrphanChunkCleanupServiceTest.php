<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Maispace\MaiSearch\Service\OrphanChunkCleanupService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class OrphanChunkCleanupServiceTest extends TestCase
{
    private const string TABLE = 'tx_maisearch_chunks';

    private ConnectionPool&MockObject $connectionPool;
    private OrphanChunkCleanupService $subject;

    protected function setUp(): void
    {
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->subject = new OrphanChunkCleanupService($this->connectionPool);
    }

    private function createQueryBuilderMock(): QueryBuilder&MockObject
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('1 = 1');
        $expr->method('gt')->willReturn('chunk_index > 0');
        $queryBuilder->method('expr')->willReturn($expr);

        return $queryBuilder;
    }

    #[Test]
    public function cleanupOrphansDeletesChunksBeyondCurrentCount(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $queryBuilder->expects(self::once())->method('delete')->with(self::TABLE)->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn(2);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertSame(2, $this->subject->cleanupOrphans('news', 1, 3));
    }

    #[Test]
    public function getChunkCountReturnsCountFromDatabase(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('5');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertSame(5, $this->subject->getChunkCount('news', 1));
    }

    #[Test]
    public function removeAllChunksDeletesEveryChunkForTheDocument(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $queryBuilder->expects(self::once())->method('delete')->with(self::TABLE)->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn(4);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertSame(4, $this->subject->removeAllChunks('news', 1));
    }
}
