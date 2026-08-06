<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Backend\IndexedSearch;

use Maispace\MaiSearch\Backend\IndexedSearch\IndexedSearchBackend;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\IndexedSearch\Domain\Repository\IndexSearchRepository;
use TYPO3\CMS\IndexedSearch\Lexer;

class IndexedSearchBackendTest extends TestCase
{
    private IndexSearchRepository $searchRepository;
    private SiteFinder $siteFinder;
    private Lexer $lexer;
    private IndexedSearchBackend $backend;

    protected function setUp(): void
    {
        $this->searchRepository = $this->createMock(IndexSearchRepository::class);
        $this->siteFinder = $this->createMock(SiteFinder::class);
        $this->lexer = $this->createMock(Lexer::class);
        $this->backend = new IndexedSearchBackend(
            $this->searchRepository,
            $this->siteFinder,
            $this->lexer,
        );
    }

    #[Test]
    public function getIdentifierReturnsIndexedSearch(): void
    {
        self::assertSame('indexed_search', $this->backend->getIdentifier());
    }

    #[Test]
    public function getCapabilitiesReportsDegradedFeatureSet(): void
    {
        self::assertEquals(
            new BackendCapabilities(
                supportsRealtimeIndexing: false,
                supportsFacets: false,
                supportsVectorSearch: false,
                hasComparableRelevanceScore: false,
            ),
            $this->backend->getCapabilities(),
        );
    }

    #[Test]
    public function searchReturnsEmptyPageForEmptyQuery(): void
    {
        $this->searchRepository->expects(self::never())->method('doSearch');

        $page = $this->backend->search(new SearchRequest(query: ''));

        self::assertSame([], $page->results);
        self::assertSame(0, $page->total);
    }

    #[Test]
    public function searchReturnsEmptyPageWhenRepositoryFindsNothing(): void
    {
        $this->lexer->method('split2Words')->willReturnCallback(static fn(string $word): array => [$word]);
        $this->searchRepository->method('doSearch')->willReturn(false);

        $page = $this->backend->search(new SearchRequest(query: 'gymnastics'));

        self::assertSame([], $page->results);
        self::assertSame(0, $page->total);
    }

    #[Test]
    public function searchMapsResultRowsToSearchResults(): void
    {
        $this->lexer->method('split2Words')->willReturnCallback(static fn(string $word): array => [$word]);

        $uri = $this->createMock(UriInterface::class);
        $uri->method('__toString')->willReturn('https://example.com/page-42');

        $router = $this->createMock(RouterInterface::class);
        $router->method('generateUri')->willReturn($uri);

        $site = $this->createMock(Site::class);
        $site->method('getRouter')->willReturn($router);

        $this->siteFinder->method('getSiteByPageId')->with(42)->willReturn($site);

        $this->searchRepository->method('doSearch')->willReturn([
            'resultRows' => [
                [
                    'data_page_id' => 42,
                    'item_title' => 'Gymnastics Course',
                    'item_description' => 'A great course',
                    'item_crdate' => 1700000000,
                    'result_number' => 1,
                ],
            ],
            'firstRow' => [],
            'count' => 1,
        ]);

        $page = $this->backend->search(new SearchRequest(query: 'gymnastics'));

        self::assertCount(1, $page->results);
        self::assertSame(1, $page->total);
        self::assertSame('page', $page->results[0]->type);
        self::assertSame('Gymnastics Course', $page->results[0]->title);
        self::assertSame('A great course', $page->results[0]->snippet);
        self::assertSame('https://example.com/page-42', $page->results[0]->url);
    }

    #[Test]
    public function searchSkipsRowsWithUnresolvablePageId(): void
    {
        $this->lexer->method('split2Words')->willReturnCallback(static fn(string $word): array => [$word]);
        $this->siteFinder->method('getSiteByPageId')->willThrowException(new \RuntimeException('no site'));

        $this->searchRepository->method('doSearch')->willReturn([
            'resultRows' => [
                ['data_page_id' => 999, 'item_title' => 'Orphan', 'result_number' => 1],
            ],
            'firstRow' => [],
            'count' => 1,
        ]);

        $page = $this->backend->search(new SearchRequest(query: 'gymnastics'));

        self::assertSame([], $page->results);
    }

    #[Test]
    public function indexDocumentLogsWarningInsteadOfThrowing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $this->backend->setLogger($logger);

        $this->backend->indexDocument(new SearchDocumentInput(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: 'Content',
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        ));
    }

    #[Test]
    public function removeDocumentLogsWarningInsteadOfThrowing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $this->backend->setLogger($logger);

        $this->backend->removeDocument('news', 1);
    }
}
