<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Service;

use Doctrine\DBAL\Result;
use Maispace\MaiSearch\Service\ContentHashService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class ContentHashServiceTest extends TestCase
{
    private const string TABLE = 'tx_maisearch_content_hash';

    private ConnectionPool&MockObject $connectionPool;
    private ContentHashService $subject;

    protected function setUp(): void
    {
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->subject = new ContentHashService($this->connectionPool);
    }

    /**
     * @return QueryBuilder&MockObject
     */
    private function createQueryBuilderMock(?string $fetchOneResult): QueryBuilder
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('1 = 1');
        $queryBuilder->method('expr')->willReturn($expr);

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($fetchOneResult ?? false);
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    #[Test]
    public function getContentHashReturnsNullWhenNoRowExists(): void
    {
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($this->createQueryBuilderMock(null));

        self::assertNull($this->subject->getContentHash('news', 1));
    }

    #[Test]
    public function getContentHashReturnsStoredHash(): void
    {
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with(self::TABLE)
            ->willReturn($this->createQueryBuilderMock('abc123'));

        self::assertSame('abc123', $this->subject->getContentHash('news', 1));
    }

    #[Test]
    public function hasContentChangedIsTrueWhenNoExistingHash(): void
    {
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($this->createQueryBuilderMock(null));

        self::assertTrue($this->subject->hasContentChanged('news', 1, 'newhash'));
    }

    #[Test]
    public function hasContentChangedIsTrueWhenHashDiffers(): void
    {
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($this->createQueryBuilderMock('oldhash'));

        self::assertTrue($this->subject->hasContentChanged('news', 1, 'newhash'));
    }

    #[Test]
    public function hasContentChangedIsFalseWhenHashMatches(): void
    {
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($this->createQueryBuilderMock('samehash'));

        self::assertFalse($this->subject->hasContentChanged('news', 1, 'samehash'));
    }

    #[Test]
    public function setContentHashInsertsWhenNoExistingRow(): void
    {
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($this->createQueryBuilderMock(null));

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                self::TABLE,
                self::callback(static function (array $data): bool {
                    self::assertSame('news', $data['document_type']);
                    self::assertSame(1, $data['document_uid']);
                    self::assertSame('newhash', $data['content_hash']);

                    return true;
                }),
            );
        $connection->expects(self::never())->method('update');

        $this->connectionPool->method('getConnectionForTable')->with(self::TABLE)->willReturn($connection);

        $this->subject->setContentHash('news', 1, 'newhash');
    }

    #[Test]
    public function setContentHashUpdatesWhenRowAlreadyExists(): void
    {
        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($this->createQueryBuilderMock('oldhash'));

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('update')
            ->with(
                self::TABLE,
                self::callback(static function (array $data): bool {
                    self::assertSame('newhash', $data['content_hash']);

                    return true;
                }),
                ['document_type' => 'news', 'document_uid' => 1],
            );
        $connection->expects(self::never())->method('insert');

        $this->connectionPool->method('getConnectionForTable')->with(self::TABLE)->willReturn($connection);

        $this->subject->setContentHash('news', 1, 'newhash');
    }

    #[Test]
    public function removeContentHashDeletesMatchingRow(): void
    {
        $queryBuilder = $this->createQueryBuilderMock(null);
        $queryBuilder->expects(self::once())->method('delete')->with(self::TABLE)->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn(1);

        $this->connectionPool->method('getQueryBuilderForTable')->with(self::TABLE)->willReturn($queryBuilder);

        $this->subject->removeContentHash('news', 1);
    }

    #[Test]
    public function computeContentHashReturnsSha256OfContent(): void
    {
        self::assertSame(hash('sha256', 'hello world'), $this->subject->computeContentHash('hello world'));
    }

    #[Test]
    public function removeAllHashesTruncatesTheTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('truncate')->with(self::TABLE);

        $this->connectionPool->method('getConnectionForTable')->with(self::TABLE)->willReturn($connection);

        $this->subject->removeAllHashes();
    }
}
