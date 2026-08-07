<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Doctrine\DBAL\Result;
use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Service\IndexQueueProcessor;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final class IndexQueueProcessorTest extends TestCase
{
    private IndexQueueRepository&MockObject $indexQueueRepository;

    private IndexerRegistry&MockObject $indexerRegistry;

    private ConnectionPool&MockObject $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->indexQueueRepository = $this->createMock(IndexQueueRepository::class);
        $this->indexerRegistry = $this->createMock(IndexerRegistry::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
    }

    /**
     * SiteFinder is readonly and its collaborators are as well; an uninitialized
     * instance is fine wherever a test wants language resolution to fail and
     * fall back to null, same as "no site configured".
     */
    private function brokenSiteFinder(): SiteFinder
    {
        return new \ReflectionClass(SiteFinder::class)->newInstanceWithoutConstructor();
    }

    /**
     * Builds a real SiteFinder whose getAllSites() returns the given sites, by
     * reflection-injecting a mocked SiteConfiguration into the otherwise
     * uninitialized readonly instance.
     *
     * @param array<int, Site> $sites
     */
    private function siteFinderReturning(array $sites): SiteFinder
    {
        $siteConfiguration = $this->createMock(SiteConfiguration::class);
        $siteConfiguration->method('getAllExistingSites')->willReturn($sites);

        $siteFinder = new \ReflectionClass(SiteFinder::class)->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(SiteFinder::class, 'siteConfiguration');
        $property->setValue($siteFinder, $siteConfiguration);

        return $siteFinder;
    }

    private function processor(SiteFinder $siteFinder): IndexQueueProcessor
    {
        return new IndexQueueProcessor(
            $this->indexQueueRepository,
            $this->indexerRegistry,
            $this->connectionPool,
            $siteFinder,
        );
    }

    /**
     * @return QueryBuilder&MockObject
     */
    private function queryBuilderReturningRow(?array $row): QueryBuilder
    {
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('uid = :dcValue1');
        $queryBuilder->method('expr')->willReturn($expr);

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn($row ?? false);
        $queryBuilder->method('executeQuery')->willReturn($result);

        return $queryBuilder;
    }

    private function job(int $uid, string $table, int $recordUid, string $operation): array
    {
        return [
            'uid' => $uid,
            'table_name' => $table,
            'record_uid' => $recordUid,
            'operation' => $operation,
        ];
    }

    // ─── processBatch ───────────────────────────────────────────────────────

    #[Test]
    public function processBatchReturnsZeroAndDoesNothingWhenQueueIsEmpty(): void
    {
        $this->indexQueueRepository->method('findOpenBatch')->willReturn([]);
        $this->indexQueueRepository->expects(self::never())->method('markProcessing');

        $processor = $this->processor($this->brokenSiteFinder());

        self::assertSame(0, $processor->processBatch());
    }

    #[Test]
    public function processBatchMarksAllFoundJobsAsProcessingBeforeHandlingThem(): void
    {
        $jobs = [
            $this->job(1, 'pages', 10, IndexQueueRepository::OPERATION_REMOVE),
            $this->job(2, 'pages', 11, IndexQueueRepository::OPERATION_REMOVE),
        ];
        $this->indexQueueRepository->method('findOpenBatch')->willReturn($jobs);
        $this->indexQueueRepository->expects(self::once())->method('markProcessing')->with([1, 2]);

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $this->indexerRegistry->method('getIndexerForTable')->willReturn($indexer);

        $processor = $this->processor($this->brokenSiteFinder());

        self::assertSame(2, $processor->processBatch());
    }

    // ─── remove jobs ────────────────────────────────────────────────────────

    #[Test]
    public function removeJobCallsRemoveRecordAndMarksDone(): void
    {
        $this->indexQueueRepository->method('findOpenBatch')->willReturn([
            $this->job(1, 'pages', 10, IndexQueueRepository::OPERATION_REMOVE),
        ]);

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects(self::once())->method('removeRecord')->with(10, 'pages');
        $this->indexerRegistry->method('getIndexerForTable')->willReturn($indexer);

        $this->indexQueueRepository->expects(self::once())->method('markDone')->with(1);
        $this->indexQueueRepository->expects(self::never())->method('markFailed');

        $processor = $this->processor($this->brokenSiteFinder());
        $processor->processBatch();
    }

    // ─── index jobs ─────────────────────────────────────────────────────────

    #[Test]
    public function indexJobFetchesTheCurrentRecordAndIndexesIt(): void
    {
        $this->indexQueueRepository->method('findOpenBatch')->willReturn([
            $this->job(1, 'pages', 10, IndexQueueRepository::OPERATION_INDEX),
        ]);

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer
            ->expects(self::once())
            ->method('indexRecord')
            ->with(
                self::callback(static fn(object $record): bool => $record->uid === 10),
                self::isInstanceOf(IndexingContext::class),
            );
        $this->indexerRegistry->method('getIndexerForTable')->willReturn($indexer);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with('pages')
            ->willReturn($this->queryBuilderReturningRow(['uid' => 10, 'sys_language_uid' => 0]));

        $this->indexQueueRepository->expects(self::once())->method('markDone')->with(1);

        $processor = $this->processor($this->brokenSiteFinder());
        $processor->processBatch();
    }

    #[Test]
    public function indexJobDegradesToRemovalWhenRecordNoLongerExists(): void
    {
        $this->indexQueueRepository->method('findOpenBatch')->willReturn([
            $this->job(1, 'pages', 10, IndexQueueRepository::OPERATION_INDEX),
        ]);

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects(self::never())->method('indexRecord');
        $indexer->expects(self::once())->method('removeRecord')->with(10, 'pages');
        $this->indexerRegistry->method('getIndexerForTable')->willReturn($indexer);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with('pages')
            ->willReturn($this->queryBuilderReturningRow(null));

        $this->indexQueueRepository->expects(self::once())->method('markDone')->with(1);

        $processor = $this->processor($this->brokenSiteFinder());
        $processor->processBatch();
    }

    #[Test]
    public function indexJobResolvesLanguageCodeFromTheMatchingSiteLanguage(): void
    {
        $this->indexQueueRepository->method('findOpenBatch')->willReturn([
            $this->job(1, 'pages', 10, IndexQueueRepository::OPERATION_INDEX),
        ]);

        $locale = $this->createMock(Locale::class);
        $locale->method('getLanguageCode')->willReturn('de');

        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn(1);
        $language->method('getLocale')->willReturn($locale);

        $site = $this->createMock(Site::class);
        $site->method('getLanguages')->willReturn([$language]);

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer
            ->expects(self::once())
            ->method('indexRecord')
            ->with(
                self::anything(),
                self::callback(static fn(IndexingContext $context): bool => $context->languageCode === 'de' && $context->languageUid === 1),
            );
        $this->indexerRegistry->method('getIndexerForTable')->willReturn($indexer);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with('pages')
            ->willReturn($this->queryBuilderReturningRow(['uid' => 10, 'sys_language_uid' => 1]));

        $processor = $this->processor($this->siteFinderReturning([$site]));
        $processor->processBatch();
    }

    // ─── failure handling ───────────────────────────────────────────────────

    #[Test]
    public function jobMarksFailedWhenNoIndexerIsRegisteredForTheTable(): void
    {
        $this->indexQueueRepository->method('findOpenBatch')->willReturn([
            $this->job(1, 'tx_unknown_table', 10, IndexQueueRepository::OPERATION_INDEX),
        ]);
        $this->indexerRegistry->method('getIndexerForTable')->willReturn(null);

        $this->indexQueueRepository
            ->expects(self::once())
            ->method('markFailed')
            ->with(1, self::stringContains('tx_unknown_table'));
        $this->indexQueueRepository->expects(self::never())->method('markDone');

        $processor = $this->processor($this->brokenSiteFinder());
        $processor->processBatch();
    }

    #[Test]
    public function jobMarksFailedWhenIndexerThrows(): void
    {
        $this->indexQueueRepository->method('findOpenBatch')->willReturn([
            $this->job(1, 'pages', 10, IndexQueueRepository::OPERATION_REMOVE),
        ]);

        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->method('removeRecord')->willThrowException(new \RuntimeException('Solr unreachable'));
        $this->indexerRegistry->method('getIndexerForTable')->willReturn($indexer);

        $this->indexQueueRepository
            ->expects(self::once())
            ->method('markFailed')
            ->with(1, 'Solr unreachable');
        $this->indexQueueRepository->expects(self::never())->method('markDone');

        $processor = $this->processor($this->brokenSiteFinder());
        $processor->processBatch();
    }
}
