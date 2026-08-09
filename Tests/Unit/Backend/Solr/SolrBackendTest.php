<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Backend\Solr;

use Maispace\MaiSearch\Backend\Solr\SolrBackend;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Domain\Solr\SearchResponse;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use Maispace\MaiSearch\Facet\FacetRegistry;
use Maispace\MaiSearch\Facet\TypeFacet;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

class SolrBackendTest extends TestCase
{
    private ConnectionFactory $connectionFactory;
    private ResultFormatterRegistry $resultFormatterRegistry;
    private IndexManagementService $indexManagementService;
    private ProviderRegistry $providerRegistry;
    private FacetRegistry $facetRegistry;
    private SolrBackend $backend;

    protected function setUp(): void
    {
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
        $this->resultFormatterRegistry = $this->createMock(ResultFormatterRegistry::class);
        $this->indexManagementService = $this->createMock(IndexManagementService::class);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->facetRegistry = new FacetRegistry();
        $this->facetRegistry->addFacet(new TypeFacet('type', 'Type'));
        $this->backend = new SolrBackend(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $this->indexManagementService,
            $this->providerRegistry,
            $this->facetRegistry,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createSolrDocArray(
        string $type = 'news',
        string $title = 'Test Title',
        string $content = 'Test content body',
        string $url = '/news/test-title',
        float $score = 0.95,
    ): array {
        return [
            'type_s' => $type,
            'title_s' => $title,
            'content_t' => $content,
            'url_s' => $url,
            'score' => $score,
        ];
    }

    /**
     * @param list<array<string, mixed>> $docs
     * @param array<string, int> $types
     */
    private function createSearchResponse(array $docs, int $numFound = 0, array $types = []): SearchResponse
    {
        return new SearchResponse(
            $docs,
            $numFound > 0 ? $numFound : count($docs),
            ['type' => $types],
        );
    }

    private function mockSolrClient(SearchResponse $response): SolrClientInterface
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client->method('search')->willReturn($response);

        return $client;
    }

    #[Test]
    public function getIdentifierReturnsSolr(): void
    {
        self::assertSame('solr', $this->backend->getIdentifier());
    }

    #[Test]
    public function getCapabilitiesReportsFullFeatureSet(): void
    {
        $capabilities = $this->backend->getCapabilities();

        self::assertEquals(
            new BackendCapabilities(
                supportsRealtimeIndexing: true,
                supportsFacets: true,
                supportsVectorSearch: true,
                hasComparableRelevanceScore: true,
            ),
            $capabilities,
        );
    }

    #[Test]
    public function searchReturnsEmptyPageForEmptyQuery(): void
    {
        $result = $this->backend->search(new SearchRequest(query: ''));

        self::assertSame([], $result->results);
        self::assertSame(0, $result->total);
    }

    #[Test]
    public function searchReturnsEmptyPageForWhitespaceQuery(): void
    {
        $result = $this->backend->search(new SearchRequest(query: '   '));

        self::assertSame([], $result->results);
    }

    #[Test]
    public function searchPassesLimitAndOffsetToSolrQuery(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                return $query->getQuery() === '(title_t:(test) OR content_t:(test))'
                    && $query->getRows() === 10
                    && $query->getStart() === 5;
            }))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test', limit: 10, offset: 5));
    }

    #[Test]
    public function searchAppliesTypeFilter(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                $filters = $query->getFilterQueries();

                return count($filters) === 1
                    && $filters[0]['query'] === 'type_s:news'
                    && $filters[0]['tag'] === 'typefilter';
            }))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test', type: 'news'));
    }

    #[Test]
    public function searchReturnsMappedSearchResults(): void
    {
        $formatter = $this->createMock(SearchResultFormatterInterface::class);
        $formatter->method('getType')->willReturn('news');
        $formatter->method('formatResult')->willReturnCallback(
            static fn(array $doc): SearchResult => new SearchResult(
                type: 'news',
                title: $doc['title_s'] ?? '',
                snippet: $doc['content_t'] ?? '',
                url: $doc['url_s'] ?? '',
                icon: 'news-icon',
                date: new \DateTime('2026-05-23T12:00:00Z'),
                score: (float) ($doc['score'] ?? 0.0),
            ),
        );

        $this->resultFormatterRegistry->method('getFormatter')->with('news')->willReturn($formatter);
        $this->connectionFactory->method('getConnection')->willReturn(
            $this->mockSolrClient($this->createSearchResponse([
                $this->createSolrDocArray(
                    type: 'news',
                    title: 'News Title',
                    content: 'News content',
                    url: '/news/slug',
                    score: 0.85,
                ),
            ], 1, ['news' => 1])),
        );

        $page = $this->backend->search(new SearchRequest(query: 'news'));

        self::assertCount(1, $page->results);
        self::assertSame(1, $page->total);
        self::assertSame('news', $page->results[0]->type);
        self::assertSame('News Title', $page->results[0]->title);

        self::assertCount(1, $page->facets);
        $typeFacet = $page->getFacet('type');
        self::assertNotNull($typeFacet);
        self::assertSame('Type', $typeFacet->getLabel());
        self::assertCount(1, $typeFacet->getValues());
        self::assertSame('news', $typeFacet->getValues()[0]->value);
        self::assertSame(1, $typeFacet->getValues()[0]->count);
    }

    #[Test]
    public function searchSkipsDocumentWithoutTypeField(): void
    {
        $this->connectionFactory->method('getConnection')->willReturn(
            $this->mockSolrClient($this->createSearchResponse([
                [
                    'title_s' => 'No Type',
                    'content_t' => 'Missing type_s',
                    'url_s' => '/no-type',
                ],
            ])),
        );

        $page = $this->backend->search(new SearchRequest(query: 'test'));

        self::assertSame([], $page->results);
    }

    #[Test]
    public function searchSkipsDocumentWithUnregisteredType(): void
    {
        $this->resultFormatterRegistry->method('getFormatter')->with('unknown_type')->willReturn(null);
        $this->connectionFactory->method('getConnection')->willReturn(
            $this->mockSolrClient($this->createSearchResponse([
                $this->createSolrDocArray(type: 'unknown_type', title: 'Unknown'),
            ])),
        );

        $page = $this->backend->search(new SearchRequest(query: 'test'));

        self::assertSame([], $page->results);
    }

    #[Test]
    public function searchPassesSiteLanguageToConnectionFactory(): void
    {
        $language = $this->createMock(SiteLanguage::class);
        $client = $this->mockSolrClient($this->createSearchResponse([]));

        $this->connectionFactory
            ->expects(self::once())
            ->method('getConnection')
            ->with($language)
            ->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test', language: $language));
    }

    #[Test]
    public function searchDoesNotAddRqParamWhenRagDisabled(): void
    {
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->expects(self::never())->method('embed');
        $this->providerRegistry->method('getActiveEmbeddingProvider')->willReturn($embeddingProvider);

        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static fn(SearchQuery $query): bool => $query->getParams() === []))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test', ragEnabled: false));
    }

    #[Test]
    public function searchAddsRqParamWhenRagEnabled(): void
    {
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('embed')->willReturn([0.1, 0.2, 0.3]);
        $this->providerRegistry->method('getActiveEmbeddingProvider')->willReturn($embeddingProvider);

        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                $params = $query->getParams();

                // rq must be a Solr RankQuery — {!knn} alone isn't one, so the
                // KNN clause lives in rqq and rq wraps it via {!rerank}.
                return isset($params['rq'])
                    && str_contains((string) $params['rq'], '{!rerank')
                    && str_contains((string) $params['rq'], '$rqq')
                    && isset($params['rqq'])
                    && str_contains((string) $params['rqq'], '{!knn')
                    && str_contains((string) $params['rqq'], SchemaManager::VECTOR_FIELD_NAME);
            }))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test', ragEnabled: true));
    }

    #[Test]
    public function indexDocumentDelegatesToIndexManagementService(): void
    {
        $this->indexManagementService
            ->expects(self::once())
            ->method('addDocumentForLanguageCode')
            ->with(self::isInstanceOf(\Maispace\MaiSearch\Domain\Solr\Document::class), 'de');

        $this->backend->indexDocument(new SearchDocumentInput(
            type: 'news',
            uid: 42,
            title: 'Title',
            content: 'Content',
            url: '/news/42',
            crdate: new \DateTimeImmutable('2026-05-23'),
            boost: 1.0,
            additionalFields: ['languageCode' => 'de'],
        ));
    }

    #[Test]
    public function removeDocumentDelegatesToIndexManagementService(): void
    {
        $this->indexManagementService
            ->expects(self::once())
            ->method('deleteRecord')
            ->with('news', 42);

        $this->backend->removeDocument('news', 42);
    }
}
