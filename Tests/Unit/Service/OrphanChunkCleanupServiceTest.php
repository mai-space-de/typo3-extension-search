<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Maispace\MaiSearch\Service\OrphanChunkCleanupService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
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
        $queryBuilder->method('selectLiteral')->willReturnSelf();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('1 = 1');
        $expr->method('gt')->willReturn('chunk_index > 0');
        $expr->method('gte')->willReturn('chunk_index >= 0');
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

    // ── chunk hash bookkeeping (SPEC-v3-caching-chunking.md Phase D) ────────────

    #[Test]
    public function getChunkHashReturnsNullWhenNothingStored(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertNull($this->subject->getChunkHash('news', 1, 0));
    }

    #[Test]
    public function getChunkHashReturnsStoredHash(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('abc123');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertSame('abc123', $this->subject->getChunkHash('news', 1, 0));
    }

    #[Test]
    public function hasChunkChangedIsTrueWhenNoHashStoredYet(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertTrue($this->subject->hasChunkChanged('news', 1, 0, hash('sha256', 'text')));
    }

    #[Test]
    public function hasChunkChangedIsFalseWhenHashMatches(): void
    {
        $hash = hash('sha256', 'same text');
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($hash);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertFalse($this->subject->hasChunkChanged('news', 1, 0, $hash));
    }

    #[Test]
    public function setChunkHashInsertsWhenNoRowExistsYet(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);
        $queryBuilder->method('executeQuery')->willReturn($result);
        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                self::TABLE,
                self::callback(static function (array $data): bool {
                    self::assertSame('news', $data['document_type']);
                    self::assertSame(1, $data['document_uid']);
                    self::assertSame(0, $data['chunk_index']);
                    self::assertSame('chunk text', $data['chunk_content']);
                    self::assertSame('hash-value', $data['chunk_hash']);

                    return true;
                }),
            );
        $connection->expects(self::never())->method('update');
        $this->connectionPool->method('getConnectionForTable')->with(self::TABLE)->willReturn($connection);

        $this->subject->setChunkHash('news', 1, 0, 'hash-value', 'chunk text');
    }

    #[Test]
    public function setChunkHashUpdatesWhenARowAlreadyExists(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('old-hash');
        $queryBuilder->method('executeQuery')->willReturn($result);
        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');
        $connection
            ->expects(self::once())
            ->method('update')
            ->with(
                self::TABLE,
                self::callback(static function (array $data): bool {
                    self::assertSame('new hash', $data['chunk_hash']);

                    return true;
                }),
                ['document_type' => 'news', 'document_uid' => 1, 'chunk_index' => 0],
            );
        $this->connectionPool->method('getConnectionForTable')->with(self::TABLE)->willReturn($connection);

        $this->subject->setChunkHash('news', 1, 0, 'new hash', 'updated text');
    }

    #[Test]
    public function findOrphanChunkIndicesReturnsIndicesAtOrBeyondCurrentCount(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn(['3', '4']);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertSame([3, 4], $this->subject->findOrphanChunkIndices('news', 1, 3));
    }

    #[Test]
    public function findOrphanChunkIndicesReturnsEmptyListWhenNothingOrphaned(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertSame([], $this->subject->findOrphanChunkIndices('news', 1, 5));
    }

    // ── Phase F: mai-search:cleanup-chunks support ───────────────────────────────

    #[Test]
    public function findDocumentUidsWithChunksReturnsDistinctUidsForType(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn(['1', '2', '3']);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertSame([1, 2, 3], $this->subject->findDocumentUidsWithChunks('news'));
    }

    #[Test]
    public function removeAllHashesTruncatesTheTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('truncate')->with(self::TABLE);

        $this->connectionPool->method('getConnectionForTable')->with(self::TABLE)->willReturn($connection);

        $this->subject->removeAllHashes();
    }

    #[Test]
    public function findDocumentUidsWithChunksReturnsEmptyListWhenNoneTracked(): void
    {
        $queryBuilder = $this->createQueryBuilderMock();
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        self::assertSame([], $this->subject->findDocumentUidsWithChunks('news'));
    }
}
