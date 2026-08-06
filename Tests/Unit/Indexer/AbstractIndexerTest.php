<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Indexer;

use Maispace\MaiSearch\Domain\Model\IndexingContext;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Service\SearchBackendInterface;
use Maispace\MaiSearch\Indexer\AbstractIndexer;
use Maispace\MaiSearch\Service\BackendRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

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
}
