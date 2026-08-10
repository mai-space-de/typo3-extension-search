<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Backend\Meilisearch\ConnectionFactory as MeilisearchConnectionFactory;
use Maispace\MaiSearch\Backend\Meilisearch\IndexNameResolver as MeilisearchIndexNameResolver;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchClientInterface;
use Maispace\MaiSearch\Backend\Meilisearch\SearchResponse as MeilisearchSearchResponse;
use Maispace\MaiSearch\Domain\Service\EmbeddingProviderInterface;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\Document;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Domain\Solr\SearchResponse;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\ContentHashService;
use Maispace\MaiSearch\Service\IndexerRegistry;
use Maispace\MaiSearch\Service\IndexQueueRepository;
use Maispace\MaiSearch\Service\OrphanChunkCleanupService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class IndexManagementServiceTest extends TestCase
{
    private ConnectionFactory&MockObject $connectionFactory;

    private SolrClientInterface&MockObject $solrClient;

    private ProviderRegistry&MockObject $providerRegistry;

    private EmbeddingProviderInterface&MockObject $embeddingProvider;

    private IndexManagementService $service;

    protected function setUp(): void
    {
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
        $this->solrClient = $this->createMock(SolrClientInterface::class);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $this->embeddingProvider->method('getMaxInputTokens')->willReturn(8191);
        $this->embeddingProvider->method('embed')->willReturn([]);
        $this->providerRegistry->method('getActiveEmbeddingProvider')->willReturn($this->embeddingProvider);

        $this->connectionFactory->method('getConnection')->willReturn($this->solrClient);
        $this->connectionFactory->method('getConnectionForLanguageCode')->willReturn($this->solrClient);

        $this->service = new IndexManagementService($this->connectionFactory, $this->providerRegistry);
    }

    private function wrapAnswerCache(FrontendInterface $cache): AnswerCacheService
    {
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('maisearch_answer')->willReturn($cache);

        return new AnswerCacheService($cacheManager);
    }

    private function createDocumentWithContent(string $content): Document
    {
        $document = new Document();
        $document->setField('id', 'news-1');
        $document->setField('type_s', 'news');
        $document->setField('content_t', $content);

        return $document;
    }

    #[Test]
    public function addDocumentSendsDocumentToSolr(): void
    {
        $document = $this->createDocumentWithContent('Test content');

        $this->solrClient->expects(self::once())->method('addDocuments')->with([$document]);
        $this->solrClient->expects(self::once())->method('commit')->with(false, false);

        $this->service->addDocument($document);
    }

    #[Test]
    public function addDocumentDoesNotAddVectorFieldWhenActiveProviderReturnsNoVector(): void
    {
        $document = $this->createDocumentWithContent('Test content');

        $this->service->addDocument($document);

        self::assertArrayNotHasKey(SchemaManager::VECTOR_FIELD_NAME, $document->getFields());
    }

    #[Test]
    public function addDocumentUsesLanguageConnectionWhenProvided(): void
    {
        $language = $this->createMock(SiteLanguage::class);
        $document = $this->createDocumentWithContent('Test');

        $this->connectionFactory
            ->expects(self::once())
            ->method('getConnection')
            ->with($language);

        $this->service->addDocument($document, $language);
    }

    #[Test]
    public function addDocumentWithEmbeddingAddsVectorField(): void
    {
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('getMaxInputTokens')->willReturn(8191);
        $embeddingProvider->method('embed')->willReturn([0.001, 0.002, 0.003]);

        $providerRegistry = $this->createMock(ProviderRegistry::class);
        $providerRegistry->method('getActiveEmbeddingProvider')->willReturn($embeddingProvider);

        $service = new IndexManagementService($this->connectionFactory, $providerRegistry);
        $document = $this->createDocumentWithContent('Test content');

        $service->addDocument($document);

        self::assertSame(
            [0.001, 0.002, 0.003],
            $document->getFields()[SchemaManager::VECTOR_FIELD_NAME],
        );
    }

    #[Test]
    public function addDocumentDoesNotQuerySolrForStoredVectorWhenContentChanged(): void
    {
        $document = $this->createDocumentWithContent('Test content');

        $this->solrClient->expects(self::never())->method('search');

        $this->service->addDocument($document, null, true);
    }

    #[Test]
    public function addDocumentSkipsEmbeddingAndCarriesForwardExistingVectorWhenContentUnchanged(): void
    {
        $document = $this->createDocumentWithContent('Unchanged content');

        $this->solrClient
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(static fn(SearchQuery $query): bool => $query->getQuery() === 'id:news-1' && $query->getRows() === 1))
            ->willReturn(new SearchResponse([[SchemaManager::VECTOR_FIELD_NAME => [0.9, 0.8]]], 1));

        $this->embeddingProvider->expects(self::never())->method('embed');

        $this->service->addDocument($document, null, false);

        self::assertSame([0.9, 0.8], $document->getFields()[SchemaManager::VECTOR_FIELD_NAME]);
    }

    #[Test]
    public function addDocumentEmbedsNormallyWhenContentUnchangedButNoStoredVectorExists(): void
    {
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('getMaxInputTokens')->willReturn(8191);
        $embeddingProvider->expects(self::once())->method('embed')->willReturn([0.1, 0.2, 0.3]);

        $providerRegistry = $this->createMock(ProviderRegistry::class);
        $providerRegistry->method('getActiveEmbeddingProvider')->willReturn($embeddingProvider);

        $service = new IndexManagementService($this->connectionFactory, $providerRegistry);
        $document = $this->createDocumentWithContent('Unchanged content, never embedded before');

        $this->solrClient->method('search')->willReturn(new SearchResponse([], 0));

        $service->addDocument($document, null, false);

        self::assertSame([0.1, 0.2, 0.3], $document->getFields()[SchemaManager::VECTOR_FIELD_NAME]);
    }

    #[Test]
    public function addDocumentDoesNotFailWhenEmbeddingProviderThrows(): void
    {
        $embeddingProvider = $this->createMock(EmbeddingProviderInterface::class);
        $embeddingProvider->method('getMaxInputTokens')->willReturn(8191);
        $embeddingProvider->method('embed')->willThrowException(new \RuntimeException('provider unreachable'));

        $providerRegistry = $this->createMock(ProviderRegistry::class);
        $providerRegistry->method('getActiveEmbeddingProvider')->willReturn($embeddingProvider);

        $service = new IndexManagementService($this->connectionFactory, $providerRegistry);
        $document = $this->createDocumentWithContent('Test content');

        $service->addDocument($document);

        self::assertArrayNotHasKey(SchemaManager::VECTOR_FIELD_NAME, $document->getFields());
    }

    #[Test]
    public function deleteRecordDeletesByIdQuery(): void
    {
        $this->solrClient
            ->expects(self::once())
            ->method('deleteByQuery')
            ->with('id:news-42');
        $this->solrClient->expects(self::once())->method('commit')->with(false, false);

        $this->service->deleteRecord('news', 42);
    }

    #[Test]
    public function deleteByTypeDelegatesToClient(): void
    {
        $this->solrClient->expects(self::once())->method('deleteByType')->with('news');
        $this->solrClient->expects(self::once())->method('commit')->with(false, false);

        $this->service->deleteByType('news');
    }

    #[Test]
    public function clearIndexDeletesAllDocuments(): void
    {
        $this->solrClient->expects(self::once())->method('deleteByQuery')->with('*:*');
        $this->solrClient->expects(self::once())->method('commit')->with(false, false);

        $this->service->clearIndex();
    }

    #[Test]
    public function clearAllCoresClearsEachMappedLanguage(): void
    {
        $this->connectionFactory->method('getCoreMapping')->willReturn([
            'de' => 'core_de',
            'en' => 'core_en',
        ]);

        $this->connectionFactory
            ->expects(self::exactly(2))
            ->method('getConnectionForLanguageCode')
            ->willReturn($this->solrClient);

        $this->solrClient->expects(self::exactly(2))->method('deleteByQuery')->with('*:*');
        $this->solrClient->expects(self::exactly(2))->method('commit')->with(false, false);

        $this->service->clearAllCores();
    }

    #[Test]
    public function getIndexStatsReturnsFacetedTypeCounts(): void
    {
        $this->connectionFactory->method('getCoreMapping')->willReturn([
            'en' => 'core_en',
        ]);

        $this->solrClient
            ->method('search')
            ->with(self::callback(static function (SearchQuery $query): bool {
                return $query->getQuery() === '*:*'
                    && $query->getRows() === 0
                    && isset($query->getFacetFields()['type']);
            }))
            ->willReturn(new SearchResponse([], 3, ['type' => ['news' => 2, 'page' => 1]]));

        $stats = $this->service->getIndexStats();

        self::assertSame(3, $stats['totalDocuments']);
        self::assertSame(['news' => 2, 'page' => 1], $stats['cores']['en']['types']);
    }

    #[Test]
    public function reindexAllInvokesRegisteredIndexers(): void
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->expects(self::once())->method('indexAll');

        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('getAll')->willReturn([$indexer]);

        $this->connectionFactory->method('getCoreMapping')->willReturn([
            'en' => 'core_en',
        ]);

        $service = new IndexManagementService($this->connectionFactory, $this->providerRegistry, $registry);
        $service->reindexAll();
    }

    // ── answer-cache invalidation on index-management triggers (SPEC-v3-caching-chunking.md Phase C) ──

    #[Test]
    public function clearIndexInvalidatesTheAnswerCache(): void
    {
        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('flushByTag')->with('maisearch_answer');

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            answerCacheService: $this->wrapAnswerCache($cache),
        );

        $service->clearIndex();
    }

    #[Test]
    public function clearAllCoresInvalidatesTheAnswerCache(): void
    {
        $this->connectionFactory->method('getCoreMapping')->willReturn(['de' => 'core_de']);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('flushByTag')->with('maisearch_answer');

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            answerCacheService: $this->wrapAnswerCache($cache),
        );

        $service->clearAllCores();
    }

    #[Test]
    public function clearIndexClearsDocumentAndChunkHashTracking(): void
    {
        $contentHashService = $this->createMock(ContentHashService::class);
        $contentHashService->expects(self::once())->method('removeAllHashes');
        $chunkHashService = $this->createMock(OrphanChunkCleanupService::class);
        $chunkHashService->expects(self::once())->method('removeAllHashes');

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            contentHashService: $contentHashService,
            chunkHashService: $chunkHashService,
        );

        $service->clearIndex();
    }

    #[Test]
    public function clearAllCoresClearsDocumentAndChunkHashTracking(): void
    {
        $this->connectionFactory->method('getCoreMapping')->willReturn(['de' => 'core_de']);

        $contentHashService = $this->createMock(ContentHashService::class);
        $contentHashService->expects(self::once())->method('removeAllHashes');
        $chunkHashService = $this->createMock(OrphanChunkCleanupService::class);
        $chunkHashService->expects(self::once())->method('removeAllHashes');

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            contentHashService: $contentHashService,
            chunkHashService: $chunkHashService,
        );

        $service->clearAllCores();
    }

    #[Test]
    public function reindexAllInvalidatesTheAnswerCache(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('getAll')->willReturn([]);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('flushByTag')->with('maisearch_answer');

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            $registry,
            answerCacheService: $this->wrapAnswerCache($cache),
        );

        $service->reindexAll();
    }

    #[Test]
    public function repopulateQueueInvalidatesTheAnswerCache(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('getAll')->willReturn([]);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->expects(self::once())->method('flushByTag')->with('maisearch_answer');

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            $registry,
            answerCacheService: $this->wrapAnswerCache($cache),
        );

        $service->repopulateQueue($this->createMock(IndexQueueRepository::class));
    }

    #[Test]
    public function repopulateQueueClearsTheQueueBeforeEnqueueingNewJobs(): void
    {
        $indexer = $this->createMock(SearchIndexerInterface::class);
        $indexer->method('getTableName')->willReturn('pages');
        $indexer->method('getRecordUidsForReindex')->willReturn([1, 2]);

        $registry = $this->createMock(IndexerRegistry::class);
        $registry->method('getAll')->willReturn([$indexer]);

        $this->connectionFactory->method('getCoreMapping')->willReturn([]);

        $service = new IndexManagementService($this->connectionFactory, $this->providerRegistry, $registry);

        $repository = $this->createMock(IndexQueueRepository::class);
        $calls = [];
        $repository->method('clearAll')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'clearAll';
        });
        $repository->method('enqueue')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'enqueue';
        });

        $enqueued = $service->repopulateQueue($repository);

        self::assertSame(2, $enqueued);
        self::assertSame(['clearAll', 'enqueue', 'enqueue'], $calls);
    }

    // ── Meilisearch-aware stats/mapping/clear (backend module routing fix) ──

    private function meilisearchActiveExtensionConfiguration(): ExtensionConfiguration&MockObject
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn(['activeBackend' => 'meilisearch']);

        return $extensionConfiguration;
    }

    #[Test]
    public function getIndexStatsQueriesMeilisearchWhenMeilisearchIsActive(): void
    {
        $client = $this->createMock(MeilisearchClientInterface::class);
        $client
            ->method('search')
            ->with('mai_search', '', ['limit' => 0, 'facets' => ['type']])
            ->willReturn(new MeilisearchSearchResponse([], 5, 0, ['type' => ['news' => 3, 'page' => 2]]));

        $meilisearchConnectionFactory = $this->createMock(MeilisearchConnectionFactory::class);
        $meilisearchConnectionFactory->method('getClient')->willReturn($client);

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            extensionConfiguration: $this->meilisearchActiveExtensionConfiguration(),
            meilisearchConnectionFactory: $meilisearchConnectionFactory,
            meilisearchIndexNameResolver: new MeilisearchIndexNameResolver(),
        );

        $stats = $service->getIndexStats();

        self::assertSame(5, $stats['totalDocuments']);
        self::assertSame(['news' => 3, 'page' => 2], $stats['cores']['default']['types']);
        self::assertSame('mai_search', $stats['cores']['default']['core']);
    }

    #[Test]
    public function getCoreMappingReturnsMeilisearchIndexNameWhenMeilisearchIsActive(): void
    {
        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            extensionConfiguration: $this->meilisearchActiveExtensionConfiguration(),
            meilisearchConnectionFactory: $this->createMock(MeilisearchConnectionFactory::class),
            meilisearchIndexNameResolver: new MeilisearchIndexNameResolver(),
        );

        self::assertSame(['default' => 'mai_search'], $service->getCoreMapping());
    }

    #[Test]
    public function clearAllCoresClearsMeilisearchIndexesInsteadOfSolrWhenMeilisearchIsActive(): void
    {
        $this->solrClient->expects(self::never())->method('deleteByQuery');

        $client = $this->createMock(MeilisearchClientInterface::class);
        $client
            ->expects(self::exactly(2))
            ->method('deleteAllDocuments')
            ->with(self::callback(static fn(string $indexUid): bool => in_array($indexUid, ['mai_search', 'mai_search_chunks'], true)));

        $meilisearchConnectionFactory = $this->createMock(MeilisearchConnectionFactory::class);
        $meilisearchConnectionFactory->method('getClient')->willReturn($client);

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            extensionConfiguration: $this->meilisearchActiveExtensionConfiguration(),
            meilisearchConnectionFactory: $meilisearchConnectionFactory,
            meilisearchIndexNameResolver: new MeilisearchIndexNameResolver(),
        );

        $service->clearAllCores();
    }

    #[Test]
    public function getIndexStatsStaysOnSolrWhenMeilisearchIsConfiguredButDependenciesAreNotInjected(): void
    {
        $this->connectionFactory->method('getCoreMapping')->willReturn([]);
        $this->solrClient->method('search')->willReturn(new SearchResponse([], 0, []));

        $service = new IndexManagementService(
            $this->connectionFactory,
            $this->providerRegistry,
            extensionConfiguration: $this->meilisearchActiveExtensionConfiguration(),
        );

        $stats = $service->getIndexStats();

        self::assertSame('core_en', $stats['cores']['en']['core']);
    }
}
