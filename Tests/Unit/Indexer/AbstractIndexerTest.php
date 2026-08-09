<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Indexer;

use Doctrine\DBAL\Result;
use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Indexer\AbstractIndexer;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\BackendRegistry;
use Maispace\MaiSearch\Service\ContentHashService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

final class AbstractIndexerTest extends TestCase
{
    private BackendRegistry&MockObject $backendRegistry;
    private SearchBackendInterface&MockObject $activeBackend;
    private AbstractIndexer $subject;

    protected function setUp(): void
    {
        $this->activeBackend = $this->createMock(SearchBackendInterface::class);
        $this->backendRegistry = $this->createMock(BackendRegistry::class);
        $this->backendRegistry->method('getActive')->willReturn($this->activeBackend);

        $this->subject = new class extends AbstractIndexer {
            public function getType(): string
            {
                return 'test';
            }

            public function supports(string $table): bool
            {
                return $table === 'tx_test';
            }

            public function indexAll(IndexingContext $context): void {}

            public function indexRecord(object $record, IndexingContext $context): void {}

            public function removeRecord(int $uid, string $table): void {}

            protected function buildContent(object $record): string
            {
                return '';
            }

            protected function buildUrl(object $record): string
            {
                return '';
            }

            protected function getRecordsForIndexing(IndexingContext $context): iterable
            {
                return [];
            }

            public function publicCreateDocument(
                string $type,
                int $uid,
                string $title,
                string $content,
                string $url,
                \DateTimeInterface $crdate,
                float $boost,
                array $rootline = [],
            ): SearchDocumentInput {
                return $this->createDocument($type, $uid, $title, $content, $url, $crdate, $boost, $rootline);
            }

            public function publicSendDocument(SearchDocumentInput $document, ?string $languageCode = null): void
            {
                $this->sendDocument($document, $languageCode);
            }

            public function publicRemoveDocument(int $uid): void
            {
                $this->removeDocument($uid);
            }
        };

        $this->subject->injectBackendRegistry($this->backendRegistry);
    }

    #[Test]
    public function createDocumentBuildsSearchDocumentInputFromArguments(): void
    {
        $crdate = new \DateTimeImmutable('2026-05-23');

        $document = $this->subject->publicCreateDocument(
            type: 'news',
            uid: 42,
            title: 'Test Title',
            content: 'Test content',
            url: '/news/42',
            crdate: $crdate,
            boost: 2.0,
            rootline: ['Home', 'News'],
        );

        self::assertSame('news', $document->type);
        self::assertSame(42, $document->uid);
        self::assertSame('Test Title', $document->title);
        self::assertSame('Test content', $document->content);
        self::assertSame('/news/42', $document->url);
        self::assertSame($crdate, $document->crdate);
        self::assertSame(2.0, $document->boost);
        self::assertSame(['Home', 'News'], $document->rootline);
    }

    #[Test]
    public function sendDocumentDelegatesToActiveBackend(): void
    {
        $document = $this->subject->publicCreateDocument(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: 'Content',
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        );

        $this->activeBackend
            ->expects(self::once())
            ->method('indexDocument')
            ->with($document);

        $this->subject->publicSendDocument($document);
    }

    #[Test]
    public function sendDocumentWithLanguageCodeAddsItToAdditionalFields(): void
    {
        $document = $this->subject->publicCreateDocument(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: 'Content',
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        );

        $this->activeBackend
            ->expects(self::once())
            ->method('indexDocument')
            ->with(self::callback(
                static fn(SearchDocumentInput $sent): bool => $sent->additionalFields['languageCode'] === 'de',
            ));

        $this->subject->publicSendDocument($document, 'de');
    }

    #[Test]
    public function removeDocumentDelegatesToActiveBackendWithTypeAndUid(): void
    {
        $this->activeBackend
            ->expects(self::once())
            ->method('removeDocument')
            ->with('test', 42);

        $this->subject->publicRemoveDocument(42);
    }

    #[Test]
    public function getBoostDefaultsToOne(): void
    {
        self::assertSame(1.0, $this->subject->getBoost('test'));
    }

    // ── content-hash / answer-cache wiring (SPEC-v2.md Phase 7) ──────────────

    /**
     * ContentHashService is final and can't be mocked directly — wrap a real
     * instance backed by a mocked ConnectionPool instead, matching
     * ContentHashServiceTest's own convention. $existingHash controls what
     * getContentHash() (read twice internally: once by hasContentChanged(),
     * once by setContentHash()) reports as already stored.
     */
    private function createContentHashService(?string $existingHash, ?Connection $writeConnection = null): ContentHashService
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('executeStatement')->willReturn(1);

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('1 = 1');
        $queryBuilder->method('expr')->willReturn($expr);

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($existingHash ?? false);
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        if ($writeConnection !== null) {
            $connectionPool->method('getConnectionForTable')->willReturn($writeConnection);
        }

        return new ContentHashService($connectionPool);
    }

    private function wrapCache(FrontendInterface $cache): AnswerCacheService
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('maisearch_answer')->willReturn($cache);

        return new AnswerCacheService($cacheManager);
    }

    #[Test]
    public function sendDocumentSkipsHashTrackingWhenServicesAreNotInjected(): void
    {
        $document = $this->subject->publicCreateDocument(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: 'Content',
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        );

        $this->activeBackend->expects(self::once())->method('indexDocument');

        // No injectContentHashService()/injectAnswerCacheService() call — must not error.
        $this->subject->publicSendDocument($document);
    }

    #[Test]
    public function sendDocumentUpdatesHashAndInvalidatesCacheOnRealContentChange(): void
    {
        $document = $this->subject->publicCreateDocument(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: 'new content',
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        );

        $newHash = hash('sha256', 'new content');
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_maisearch_content_hash',
                self::callback(static function (array $data) use ($newHash): bool {
                    self::assertSame('news', $data['document_type']);
                    self::assertSame(1, $data['document_uid']);
                    self::assertSame($newHash, $data['content_hash']);

                    return true;
                }),
            );
        $contentHashService = $this->createContentHashService(existingHash: null, writeConnection: $connection);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('flushByTag')->with('context_' . md5('/news/1'));
        $answerCacheService = $this->wrapCache($cache);

        $this->subject->injectContentHashService($contentHashService);
        $this->subject->injectAnswerCacheService($answerCacheService);

        $this->activeBackend->expects(self::once())->method('indexDocument')->with($document);

        $this->subject->publicSendDocument($document);
    }

    #[Test]
    public function sendDocumentSkipsCacheInvalidationWhenContentIsUnchanged(): void
    {
        $document = $this->subject->publicCreateDocument(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: 'same content',
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        );

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('insert');
        $connection->expects(self::never())->method('update');
        $contentHashService = $this->createContentHashService(
            existingHash: hash('sha256', 'same content'),
            writeConnection: $connection,
        );

        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::never())->method('flushByTag');
        $answerCacheService = $this->wrapCache($cache);

        $this->subject->injectContentHashService($contentHashService);
        $this->subject->injectAnswerCacheService($answerCacheService);

        $this->activeBackend->expects(self::once())->method('indexDocument');

        $this->subject->publicSendDocument($document);
    }

    #[Test]
    public function removeDocumentClearsTheStoredContentHashWhenServiceIsInjected(): void
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->expects(self::once())->method('delete')->with('tx_maisearch_content_hash')->willReturnSelf();
        $queryBuilder->method('executeStatement')->willReturn(1);

        $expr = $this->createMock(ExpressionBuilder::class);
        $expr->method('eq')->willReturn('1 = 1');
        $queryBuilder->method('expr')->willReturn($expr);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $this->subject->injectContentHashService(new ContentHashService($connectionPool));

        $this->subject->publicRemoveDocument(42);
    }
}
