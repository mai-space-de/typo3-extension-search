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
use Maispace\MaiSearch\Domain\Model\ChunkDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchDocumentInput;
use Maispace\MaiSearch\Domain\Model\SearchRequest;
use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;
use Maispace\MaiSearch\Facet\FacetRegistry;
use Maispace\MaiSearch\Facet\TypeFacet;
use Maispace\MaiSearch\Search\HybridRankFusionService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use Maispace\MaiSearch\Service\QueryEmbeddingCacheService;
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
    private QueryEmbeddingCacheService&MockObject $queryEmbeddingCache;
    private ProviderRegistry&MockObject $providerRegistry;
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
        $this->queryEmbeddingCache = $this->createMock(QueryEmbeddingCacheService::class);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);

        $this->backend = new MeilisearchBackend(
            $this->connectionFactory,
            $this->indexNameResolver,
            $this->resultFormatterRegistry,
            $this->facetRegistry,
            $this->queryEmbeddingCache,
            $this->providerRegistry,
            new HybridRankFusionService(),
        );
    }

    #[Test]
    public function getIdentifierReturnsMeilisearch(): void
    {
        self::assertSame('meilisearch', $this->backend->getIdentifier());
    }

    #[Test]
    public function getCapabilitiesReportsVectorSearchSupport(): void
    {
        self::assertEquals(
            new BackendCapabilities(
                supportsRealtimeIndexing: true,
                supportsFacets: true,
                supportsVectorSearch: true,
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
    public function searchStripsGermanStopWordsFromKeywordQuery(): void
    {
        $locale = new Locale('de');
        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLocale')->willReturn($locale);

        $this->client
            ->expects(self::once())
            ->method('search')
            ->with('mai_search_de', 'Ladies Club?', self::anything())
            ->willReturn(new SearchResponse([], 0, 1));

        $this->backend->search(new SearchRequest(
            query: 'Was ist der Ladies Club?',
            language: $language,
        ));
    }

    #[Test]
    public function searchRestrictsMatchingToTitleAndContentWithLastTermStrategy(): void
    {
        $this->client
            ->expects(self::once())
            ->method('search')
            ->with(self::anything(), self::anything(), self::callback(
                static fn(array $params): bool => ($params['matchingStrategy'] ?? null) === 'last'
                    && ($params['attributesToSearchOn'] ?? null) === ['title', 'content'],
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

    // ── result-list rank fusion (SPEC-v3-caching-chunking.md Phase E) ───────────

    private function makePassthroughFormatter(): SearchResultFormatterInterface
    {
        $formatter = $this->createMock(SearchResultFormatterInterface::class);
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
    public function searchDoesNotRunChunkSearchWhenRagDisabled(): void
    {
        $this->queryEmbeddingCache->expects(self::never())->method('embed');

        $this->client->expects(self::once())->method('search')->willReturn(new SearchResponse([
            ['id' => 'news-1', 'type' => 'news', 'title' => 'A', 'url' => '/a'],
        ], 1, 1));
        $this->resultFormatterRegistry->method('getFormatter')->willReturn($this->makePassthroughFormatter());

        $this->backend->search(new SearchRequest(query: 'test', ragEnabled: false));
    }

    #[Test]
    public function searchReordersResultsByFusedRankWhenRagEnabled(): void
    {
        $this->resultFormatterRegistry->method('getFormatter')->with('news')->willReturn($this->makePassthroughFormatter());
        $this->queryEmbeddingCache->method('embed')->willReturn([0.1, 0.2]);

        $this->client->method('search')->willReturnCallback(function (string $indexUid, string $query, array $params): SearchResponse {
            if ($indexUid === 'mai_search_chunks') {
                // Chunk vector search: /b ranks first semantically.
                return new SearchResponse([
                    ['parentType' => 'news', 'parentTitle' => 'B', 'parentUrl' => '/b', 'content' => 'chunk about b', '_rankingScore' => 0.9],
                ], 1, 1);
            }

            // Text search: /a ranks first.
            return new SearchResponse([
                ['type' => 'news', 'title' => 'A', 'url' => '/a'],
                ['type' => 'news', 'title' => 'B', 'url' => '/b'],
            ], 2, 1);
        });

        $page = $this->backend->search(new SearchRequest(query: 'test', ragEnabled: true));

        self::assertSame('/b', $page->results[0]->url);
        self::assertSame(2, $page->total);
    }

    // ── ChunkSearchBackendInterface (SPEC-v3-caching-chunking.md Phase E) ───────

    private function makeChunkDocument(?string $languageCode = null): ChunkDocumentInput
    {
        return new ChunkDocumentInput(
            parentType: 'news',
            parentUid: 42,
            parentTitle: 'Title',
            parentUrl: '/news/42',
            chunkIndex: 0,
            content: 'Chunk text',
            languageCode: $languageCode,
        );
    }

    #[Test]
    public function indexChunkEmbedsAndWritesTheChunkDocument(): void
    {
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('getMaxInputTokens')->willReturn(8191);
        $embeddingProvider->expects(self::once())->method('embed')->with('Chunk text')->willReturn([0.1, 0.2]);
        $this->providerRegistry->method('getActiveEmbeddingProvider')->willReturn($embeddingProvider);

        $captured = null;
        $capturedIndexUid = null;
        $this->client
            ->expects(self::once())
            ->method('addDocuments')
            ->willReturnCallback(function (string $indexUid, array $documents) use (&$captured, &$capturedIndexUid): void {
                $capturedIndexUid = $indexUid;
                $captured = $documents[0];
            });

        $this->backend->indexChunk($this->makeChunkDocument());

        self::assertSame('mai_search_chunks', $capturedIndexUid);
        self::assertInstanceOf(MeilisearchDocument::class, $captured);
        $fields = $captured->toArray();
        self::assertSame('news-42-chunk-0', $fields['id']);
        self::assertArrayNotHasKey('type', $fields);
        self::assertSame('news', $fields['parentType']);
        self::assertSame(42, $fields['parentUid']);
        self::assertSame('Title', $fields['parentTitle']);
        self::assertSame('/news/42', $fields['parentUrl']);
        self::assertSame(0, $fields['chunkIndex']);
        self::assertSame('Chunk text', $fields['content']);
        self::assertSame(['default' => [0.1, 0.2]], $fields['_vectors']);
    }

    #[Test]
    public function indexChunkSkipsWritingWhenEmbeddingFails(): void
    {
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('getMaxInputTokens')->willReturn(8191);
        $embeddingProvider->method('embed')->willReturn([]);
        $this->providerRegistry->method('getActiveEmbeddingProvider')->willReturn($embeddingProvider);

        $this->client->expects(self::never())->method('addDocuments');

        $this->backend->indexChunk($this->makeChunkDocument());
    }

    #[Test]
    public function removeChunkDeletesByExactChunkId(): void
    {
        $this->client
            ->expects(self::once())
            ->method('deleteDocument')
            ->with('mai_search_chunks', 'news-42-chunk-2');

        $this->backend->removeChunk('news', 42, 2);
    }

    #[Test]
    public function removeChunksDeletesByFilterAcrossTheParentDocument(): void
    {
        $this->client
            ->expects(self::once())
            ->method('deleteDocumentsByFilter')
            ->with('mai_search_chunks', 'parentType = "news" AND parentUid = 42');

        $this->backend->removeChunks('news', 42);
    }

    #[Test]
    public function searchChunksReturnsEmptyArrayForBlankQuery(): void
    {
        $this->queryEmbeddingCache->expects(self::never())->method('embed');

        self::assertSame([], $this->backend->searchChunks('   ', null, null, 5));
    }

    #[Test]
    public function searchChunksReturnsEmptyArrayWhenEmbeddingFails(): void
    {
        $this->queryEmbeddingCache->method('embed')->willThrowException(new \RuntimeException('provider down'));

        self::assertSame([], $this->backend->searchChunks('test', null, null, 5));
    }

    #[Test]
    public function searchChunksBuildsSearchResultsFromParentFields(): void
    {
        $this->queryEmbeddingCache->method('embed')->willReturn([0.1, 0.2]);

        $this->client
            ->expects(self::once())
            ->method('search')
            ->with('mai_search_chunks', '', self::callback(static function (array $params): bool {
                return !array_key_exists('filter', $params)
                    && $params['vector'] === [0.1, 0.2]
                    && $params['hybrid'] === ['embedder' => 'default', 'semanticRatio' => 1.0];
            }))
            ->willReturn(new SearchResponse([
                [
                    'parentType' => 'news',
                    'parentTitle' => 'News Title',
                    'parentUrl' => '/news/1',
                    'content' => 'Relevant chunk text',
                    '_rankingScore' => 0.8,
                ],
            ], 1, 1));

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

        $this->client
            ->expects(self::once())
            ->method('search')
            ->with(self::anything(), self::anything(), self::callback(
                static fn(array $params): bool => $params['filter'] === 'parentType = "news"',
            ))
            ->willReturn(new SearchResponse([], 0, 1));

        $this->backend->searchChunks('test', null, 'news', 5);
    }

    #[Test]
    public function searchChunksSkipsHitWithoutParentType(): void
    {
        $this->queryEmbeddingCache->method('embed')->willReturn([0.1]);

        $this->client->method('search')->willReturn(new SearchResponse([
            ['content' => 'orphaned chunk without parent fields'],
        ], 1, 1));

        self::assertSame([], $this->backend->searchChunks('test', null, null, 5));
    }
}
