<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Backend\Meilisearch;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchBackend;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchClientInterface;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchDocument;
use Maispace\MaiSearch\Backend\Meilisearch\SearchResponse;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;
use Maispace\MaiSearch\Facet\FacetRegistry;
use Maispace\MaiSearch\Facet\TypeFacet;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class MeilisearchBackendTest extends TestCase
{
    private ConnectionFactory&MockObject $connectionFactory;
    private IndexNameResolver $indexNameResolver;
    private ResultFormatterRegistry&MockObject $resultFormatterRegistry;
    private FacetRegistry $facetRegistry;
    private MeilisearchClientInterface&MockObject $client;
    private MeilisearchBackend $backend;

    protected function setUp(): void
    {
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
        $this->indexNameResolver = new IndexNameResolver();
        $this->resultFormatterRegistry = $this->createMock(ResultFormatterRegistry::class);
        $this->facetRegistry = new FacetRegistry();
        $this->facetRegistry->addFacet(new TypeFacet('type', 'Type'));
        $this->client = $this->createMock(MeilisearchClientInterface::class);
        $this->connectionFactory->method('getClient')->willReturn($this->client);

        $this->backend = new MeilisearchBackend(
            $this->connectionFactory,
            $this->indexNameResolver,
            $this->resultFormatterRegistry,
            $this->facetRegistry,
        );
    }

    #[Test]
    public function getIdentifierReturnsMeilisearch(): void
    {
        self::assertSame('meilisearch', $this->backend->getIdentifier());
    }

    #[Test]
    public function getCapabilitiesReportsNoVectorSearchYet(): void
    {
        self::assertEquals(
            new BackendCapabilities(
                supportsRealtimeIndexing: true,
                supportsFacets: true,
                supportsVectorSearch: false,
                hasComparableRelevanceScore: true,
            ),
            $this->backend->getCapabilities(),
        );
    }

    #[Test]
    public function searchReturnsEmptyPageForEmptyQuery(): void
    {
        $this->client->expects(self::never())->method('search');

        $page = $this->backend->search(new SearchRequest(query: ''));

        self::assertSame([], $page->results);
        self::assertSame(0, $page->total);
    }

    #[Test]
    public function searchQueriesLanguageSpecificIndex(): void
    {
        $locale = new Locale('de');
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLocale')->willReturn($locale);

        $this->client
            ->expects(self::once())
            ->method('search')
            ->with('mai_search_de', 'gymnastics', self::anything())
            ->willReturn(new SearchResponse([], 0, 1));

        $this->backend->search(new SearchRequest(query: 'gymnastics', language: $language));
    }

    #[Test]
    public function searchUsesDefaultIndexWhenNoLanguageGiven(): void
    {
        $this->client
            ->expects(self::once())
            ->method('search')
            ->with('mai_search', 'gymnastics', self::anything())
            ->willReturn(new SearchResponse([], 0, 1));

        $this->backend->search(new SearchRequest(query: 'gymnastics'));
    }

    #[Test]
    public function searchAppliesTypeFilterAsMeilisearchFilterExpression(): void
    {
        $this->client
            ->expects(self::once())
            ->method('search')
            ->with(self::anything(), self::anything(), self::callback(
                static fn(array $params): bool => ($params['filter'] ?? null) === 'type = "news"',
            ))
            ->willReturn(new SearchResponse([], 0, 1));

        $this->backend->search(new SearchRequest(query: 'gymnastics', type: 'news'));
    }

    #[Test]
    public function searchOmitsFilterWhenNoTypeGiven(): void
    {
        $this->client
            ->expects(self::once())
            ->method('search')
            ->with(self::anything(), self::anything(), self::callback(
                static fn(array $params): bool => !array_key_exists('filter', $params),
            ))
            ->willReturn(new SearchResponse([], 0, 1));

        $this->backend->search(new SearchRequest(query: 'gymnastics'));
    }

    #[Test]
    public function searchMapsFacetDistributionToTypeCounts(): void
    {
        $this->client->method('search')->willReturn(new SearchResponse(
            hits: [],
            estimatedTotalHits: 3,
            processingTimeMs: 2,
            facetDistribution: ['type' => ['news' => 2, 'page' => 1]],
        ));

        $page = $this->backend->search(new SearchRequest(query: 'gymnastics'));

        self::assertSame(3, $page->total);

        $typeFacet = $page->getFacet('type');
        self::assertNotNull($typeFacet);
        $counts = [];
        foreach ($typeFacet->getValues() as $value) {
            $counts[$value->value] = $value->count;
        }
        self::assertSame(['news' => 2, 'page' => 1], $counts);
    }

    #[Test]
    public function searchMapsHitsThroughResultFormatterUsingSolrShapedFields(): void
    {
        $formatter = $this->createMock(SearchResultFormatterInterface::class);
        $formatter->method('formatResult')->willReturnCallback(
            static fn(array $doc): SearchResult => new SearchResult(
                type: 'news',
                title: $doc['title_s'] ?? '',
                snippet: $doc['content_t'] ?? '',
                url: $doc['url_s'] ?? '',
                icon: 'news-icon',
                date: null,
                score: (float) ($doc['score'] ?? 0.0),
            ),
        );
        $this->resultFormatterRegistry->method('getFormatter')->with('news')->willReturn($formatter);

        $this->client->method('search')->willReturn(new SearchResponse(
            hits: [[
                'id' => 'news-1',
                'type' => 'news',
                'title' => 'News Title',
                'content' => 'News content',
                'url' => '/news/slug',
                'uid' => 1,
                '_rankingScore' => 0.87,
            ]],
            estimatedTotalHits: 1,
            processingTimeMs: 1,
        ));

        $page = $this->backend->search(new SearchRequest(query: 'gymnastics'));

        self::assertCount(1, $page->results);
        self::assertSame('News Title', $page->results[0]->title);
        self::assertSame('News content', $page->results[0]->snippet);
        self::assertSame('/news/slug', $page->results[0]->url);
        self::assertSame(0.87, $page->results[0]->score);
    }

    #[Test]
    public function searchSkipsHitsWithoutRegisteredFormatter(): void
    {
        $this->resultFormatterRegistry->method('getFormatter')->willReturn(null);

        $this->client->method('search')->willReturn(new SearchResponse(
            hits: [['id' => 'unknown-1', 'type' => 'unknown']],
            estimatedTotalHits: 1,
            processingTimeMs: 1,
        ));

        $page = $this->backend->search(new SearchRequest(query: 'gymnastics'));

        self::assertSame([], $page->results);
    }

    #[Test]
    public function indexDocumentEnsuresIndexWithTypeFilterableAttribute(): void
    {
        $this->client
            ->expects(self::once())
            ->method('ensureIndex')
            ->with('mai_search', ['type']);
        $this->client->expects(self::once())->method('addDocuments');

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
    public function indexDocumentOnlyEnsuresIndexOncePerUid(): void
    {
        $this->client->expects(self::once())->method('ensureIndex');
        $this->client->expects(self::exactly(2))->method('addDocuments');

        $input = new SearchDocumentInput(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: 'Content',
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
        );

        $this->backend->indexDocument($input);
        $this->backend->indexDocument($input);
    }

    #[Test]
    public function indexDocumentUsesLanguageSpecificIndexFromAdditionalFields(): void
    {
        $this->client
            ->expects(self::once())
            ->method('addDocuments')
            ->with('mai_search_de', self::anything());

        $this->backend->indexDocument(new SearchDocumentInput(
            type: 'news',
            uid: 1,
            title: 'Title',
            content: 'Content',
            url: '/news/1',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
            additionalFields: ['languageCode' => 'de'],
        ));
    }

    #[Test]
    public function indexDocumentBuildsMeilisearchDocumentWithMappedFields(): void
    {
        $captured = null;
        $this->client
            ->method('addDocuments')
            ->willReturnCallback(function (string $indexUid, array $documents) use (&$captured): void {
                $captured = $documents[0];
            });

        $this->backend->indexDocument(new SearchDocumentInput(
            type: 'news',
            uid: 42,
            title: 'Test Title',
            content: 'Test content',
            url: '/news/42',
            crdate: new \DateTimeImmutable('@1700000000'),
            boost: 2.0,
            rootline: ['Home', 'News'],
        ));

        self::assertInstanceOf(MeilisearchDocument::class, $captured);
        self::assertSame([
            'id' => 'news-42',
            'type' => 'news',
            'title' => 'Test Title',
            'content' => 'Test content',
            'url' => '/news/42',
            'uid' => 42,
            'crdate' => 1700000000,
            'boost' => 2.0,
            'rootline' => 'Home | News',
        ], $captured->toArray());
    }

    #[Test]
    public function removeDocumentDeletesByComposedIdFromDefaultIndex(): void
    {
        $this->client
            ->expects(self::once())
            ->method('deleteDocument')
            ->with('mai_search', 'news-42');

        $this->backend->removeDocument('news', 42);
    }
}
