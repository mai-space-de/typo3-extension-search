<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Backend\Solr;

use Maispace\MaiSearch\Backend\Solr\SolrBackend;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Model\BackendCapabilities;
use Maispace\MaiSearch\Domain\Model\ChunkDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Domain\Solr\SearchResponse;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use Maispace\MaiSearch\Facet\FacetRegistry;
use Maispace\MaiSearch\Facet\TypeFacet;
use Maispace\MaiSearch\Search\HybridRankFusionService;
use Maispace\MaiSearch\Service\QueryEmbeddingCacheService;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

class SolrBackendTest extends TestCase
{
    private ConnectionFactory $connectionFactory;
    private ResultFormatterRegistry $resultFormatterRegistry;
    private IndexManagementService $indexManagementService;
    private QueryEmbeddingCacheService $queryEmbeddingCache;
    private FacetRegistry $facetRegistry;
    private SolrBackend $backend;

    protected function setUp(): void
    {
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
        $this->resultFormatterRegistry = $this->createMock(ResultFormatterRegistry::class);
        $this->indexManagementService = $this->createMock(IndexManagementService::class);
        $this->queryEmbeddingCache = $this->createMock(QueryEmbeddingCacheService::class);
        $this->facetRegistry = new FacetRegistry();
        $this->facetRegistry->addFacet(new TypeFacet('type', 'Type'));
        $this->backend = new SolrBackend(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $this->indexManagementService,
            $this->queryEmbeddingCache,
            $this->facetRegistry,
            new HybridRankFusionService(),
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
                $params = $query->getParams();

                return $query->getQuery() === 'test'
                    && ($params['defType'] ?? null) === 'edismax'
                    && ($params['qf'] ?? null) === 'title_t^3 content_t'
                    && ($params['mm'] ?? null) === '2'
                    && $query->getRows() === 10
                    && $query->getStart() === 5;
            }))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test', limit: 10, offset: 5));
    }

    #[Test]
    public function searchUsesEdismaxWithTitleBoostAndMinimumShouldMatch(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                $params = $query->getParams();

                return $query->getQuery() === 'Männer in Pulheim'
                    && ($params['defType'] ?? null) === 'edismax'
                    && ($params['qf'] ?? null) === 'title_t^3 content_t'
                    && ($params['pf'] ?? null) === 'title_t^2 content_t'
                    && ($params['mm'] ?? null) === '2';
            }))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'Männer in Pulheim'));
    }

    #[Test]
    public function searchAppliesTypeFilter(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                $typeFilter = null;
                foreach ($query->getFilterQueries() as $filter) {
                    if ($filter['tag'] === 'typefilter') {
                        $typeFilter = $filter;
                    }
                }

                return $typeFilter !== null && $typeFilter['query'] === 'type_s:news';
            }))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test', type: 'news'));
    }

    #[Test]
    public function searchAlwaysExcludesChunkDocumentsFromTheMainQuery(): void
    {
        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                foreach ($query->getFilterQueries() as $filter) {
                    if ($filter['query'] === '-type_s:chunk' && $filter['tag'] === null) {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test'));
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

    // ── result-list rank fusion (SPEC-v3-caching-chunking.md Phase D, Decision 3) ──

    #[Test]
    public function searchDoesNotRunChunkSearchWhenRagDisabled(): void
    {
        $this->queryEmbeddingCache->expects(self::never())->method('embed');

        $client = $this->createMock(SolrClientInterface::class);
        $client->expects(self::once())->method('search')->willReturn(
            $this->createSearchResponse([$this->createSolrDocArray()], 1, ['news' => 1]),
        );
        $this->connectionFactory->method('getConnection')->willReturn($client);
        $this->resultFormatterRegistry->method('getFormatter')->willReturn($this->makePassthroughFormatter());

        $this->backend->search(new SearchRequest(query: 'test', ragEnabled: false));
    }

    #[Test]
    public function searchDoesNotRunChunkSearchWhenTheMainQueryHasNoResults(): void
    {
        $this->queryEmbeddingCache->expects(self::never())->method('embed');

        $client = $this->createMock(SolrClientInterface::class);
        $client->expects(self::once())->method('search')->willReturn($this->createSearchResponse([]));
        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->search(new SearchRequest(query: 'test', ragEnabled: true));
    }

    #[Test]
    public function searchReordersResultsByFusedRankWhenRagEnabled(): void
    {
        $this->resultFormatterRegistry->method('getFormatter')->with('news')->willReturn($this->makePassthroughFormatter());
        $this->queryEmbeddingCache->method('embed')->willReturn([0.1, 0.2]);

        $client = $this->createMock(SolrClientInterface::class);
        $client->method('search')->willReturnCallback(function (SearchQuery $query): SearchResponse {
            if (str_starts_with($query->getQuery(), '{!knn')) {
                // Semantic signal: /b ranks first.
                return new SearchResponse([
                    [
                        'parent_type_s' => 'news',
                        'parent_title_s' => 'B',
                        'parent_url_s' => '/b',
                        'content_t' => 'chunk about b',
                        'score' => 0.95,
                    ],
                ], 1, []);
            }

            // Text signal: /a ranks first.
            return new SearchResponse([
                $this->createSolrDocArray(title: 'A', url: '/a'),
                $this->createSolrDocArray(title: 'B', url: '/b'),
            ], 2, []);
        });

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $page = $this->backend->search(new SearchRequest(query: 'test', ragEnabled: true));

        self::assertSame('/b', $page->results[0]->url);
        self::assertCount(2, $page->results);
        // Total/pagination stay governed by the text signal alone — fusion only reorders.
        self::assertSame(2, $page->total);
    }

    #[Test]
    public function searchKeepsTextOrderWhenChunkSearchReturnsNoSemanticHits(): void
    {
        $this->resultFormatterRegistry->method('getFormatter')->with('news')->willReturn($this->makePassthroughFormatter());
        $this->queryEmbeddingCache->method('embed')->willReturn([]);

        $client = $this->createMock(SolrClientInterface::class);
        $client->method('search')->willReturnCallback(function (SearchQuery $query): SearchResponse {
            if (str_starts_with($query->getQuery(), '{!knn')) {
                return $this->createSearchResponse([]);
            }

            return new SearchResponse([
                $this->createSolrDocArray(title: 'A', url: '/a'),
                $this->createSolrDocArray(title: 'B', url: '/b'),
            ], 2, []);
        });

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $page = $this->backend->search(new SearchRequest(query: 'test', ragEnabled: true));

        self::assertSame('/a', $page->results[0]->url);
        self::assertSame('/b', $page->results[1]->url);
    }

    private function makePassthroughFormatter(): SearchResultFormatterInterface
    {
        $formatter = $this->createMock(SearchResultFormatterInterface::class);
        $formatter->method('getType')->willReturn('news');
        $formatter->method('formatResult')->willReturnCallback(
            static fn(array $doc): SearchResult => new SearchResult(
                type: 'news',
                title: (string) ($doc['title_s'] ?? ''),
                snippet: (string) ($doc['content_t'] ?? ''),
                url: (string) ($doc['url_s'] ?? ''),
                icon: '',
                date: null,
                score: (float) ($doc['score'] ?? 0.0),
            ),
        );

        return $formatter;
    }

    #[Test]
    public function indexDocumentDelegatesToIndexManagementService(): void
    {
        $this->indexManagementService
            ->expects(self::once())
            ->method('addDocumentForLanguageCode')
            ->with(self::isInstanceOf(\Maispace\MaiSearch\Domain\Solr\Document::class), 'de', true);

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

    // ── ChunkSearchBackendInterface (SPEC-v3-caching-chunking.md Phase D) ───────

    #[Test]
    public function indexChunkBuildsAChunkDocumentAndDelegatesToIndexManagementService(): void
    {
        $this->indexManagementService
            ->expects(self::once())
            ->method('addDocumentForLanguageCode')
            ->with(
                self::callback(static function (\Maispace\MaiSearch\Domain\Solr\Document $document): bool {
                    $fields = $document->getFields();

                    return $fields['id'] === 'news-42-chunk-0'
                        && $fields['type_s'] === 'chunk'
                        && $fields['parent_type_s'] === 'news'
                        && $fields['parent_uid_i'] === 42
                        && $fields['parent_title_s'] === 'Title'
                        && $fields['parent_url_s'] === '/news/42'
                        && $fields['chunk_index_i'] === 0
                        && $fields['content_t'] === 'Chunk text';
                }),
                'de',
            );

        $this->backend->indexChunk(new ChunkDocumentInput(
            parentType: 'news',
            parentUid: 42,
            parentTitle: 'Title',
            parentUrl: '/news/42',
            chunkIndex: 0,
            content: 'Chunk text',
            languageCode: 'de',
        ));
    }

    #[Test]
    public function removeChunkDeletesByExactChunkId(): void
    {
        $this->indexManagementService
            ->expects(self::once())
            ->method('deleteById')
            ->with('news-42-chunk-2');

        $this->backend->removeChunk('news', 42, 2);
    }

    #[Test]
    public function removeChunksDeletesEveryChunkOfTheParentDocument(): void
    {
        $this->indexManagementService
            ->expects(self::once())
            ->method('deleteByQuery')
            ->with('type_s:chunk AND parent_type_s:news AND parent_uid_i:42');

        $this->backend->removeChunks('news', 42);
    }

    #[Test]
    public function searchChunksReturnsEmptyArrayForBlankQuery(): void
    {
        $this->queryEmbeddingCache->expects(self::never())->method('embed');

        self::assertSame([], $this->backend->searchChunks('   ', null, null, 5));
    }

    #[Test]
    public function searchChunksReturnsEmptyArrayWhenEmbeddingProviderFails(): void
    {
        $this->queryEmbeddingCache->method('embed')->willThrowException(new \RuntimeException('provider down'));

        self::assertSame([], $this->backend->searchChunks('test', null, null, 5));
    }

    #[Test]
    public function searchChunksReturnsEmptyArrayWhenEmbeddingIsEmpty(): void
    {
        $this->queryEmbeddingCache->method('embed')->willReturn([]);

        self::assertSame([], $this->backend->searchChunks('test', null, null, 5));
    }

    #[Test]
    public function searchChunksBuildsSearchResultsFromParentFields(): void
    {
        $this->queryEmbeddingCache->method('embed')->willReturn([0.1, 0.2]);

        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                $filters = $query->getFilterQueries();

                return str_starts_with($query->getQuery(), '{!knn')
                    && $query->getRows() === 5
                    && $filters !== []
                    && $filters[0]['query'] === 'type_s:chunk';
            }))
            ->willReturn(new SearchResponse([
                [
                    'parent_type_s' => 'news',
                    'parent_title_s' => 'News Title',
                    'parent_url_s' => '/news/1',
                    'content_t' => 'Relevant chunk text',
                    'score' => 0.8,
                ],
            ], 1, []));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $results = $this->backend->searchChunks('test', null, null, 5);

        self::assertCount(1, $results);
        self::assertSame('news', $results[0]->type);
        self::assertSame('News Title', $results[0]->title);
        self::assertSame('/news/1', $results[0]->url);
        self::assertSame('Relevant chunk text', $results[0]->snippet);
        self::assertSame(0.8, $results[0]->score);
    }

    #[Test]
    public function searchChunksAppliesTypeFilterAgainstParentType(): void
    {
        $this->queryEmbeddingCache->method('embed')->willReturn([0.1]);

        $client = $this->createMock(SolrClientInterface::class);
        $client
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                foreach ($query->getFilterQueries() as $filter) {
                    if ($filter['query'] === 'parent_type_s:news') {
                        return true;
                    }
                }

                return false;
            }))
            ->willReturn($this->createSearchResponse([]));

        $this->connectionFactory->method('getConnection')->willReturn($client);

        $this->backend->searchChunks('test', null, 'news', 5);
    }

    #[Test]
    public function searchChunksSkipsDocumentWithoutParentType(): void
    {
        $this->queryEmbeddingCache->method('embed')->willReturn([0.1]);

        $client = $this->createMock(SolrClientInterface::class);
        $client->method('search')->willReturn(new SearchResponse([
            ['content_t' => 'orphaned chunk without parent fields'],
        ], 1, []));
        $this->connectionFactory->method('getConnection')->willReturn($client);

        self::assertSame([], $this->backend->searchChunks('test', null, null, 5));
    }
}
