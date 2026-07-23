<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\SearchIndexerInterface;
use Maispace\MaiSearch\Domain\Service\VectorEmbeddingInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\Document;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Domain\Solr\SearchQuery;
use Maispace\MaiSearch\Domain\Solr\SearchResponse;
use Maispace\MaiSearch\Domain\Solr\SolrClientInterface;
use Maispace\MaiSearch\Service\IndexerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class IndexManagementServiceTest extends TestCase
{
    private ConnectionFactory&MockObject $connectionFactory;

    private SolrClientInterface&MockObject $solrClient;

    private IndexManagementService $service;

    protected function setUp(): void
    {
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
        $this->solrClient = $this->createMock(SolrClientInterface::class);

        $this->connectionFactory->method('getConnection')->willReturn($this->solrClient);
        $this->connectionFactory->method('getConnectionForLanguageCode')->willReturn($this->solrClient);

        $this->service = new IndexManagementService($this->connectionFactory);
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
    public function addDocumentDoesNotAddVectorFieldWhenNoEmbeddingService(): void
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
        $embedding = $this->createMock(VectorEmbeddingInterface::class);
        $embedding->method('getMaxInputTokens')->willReturn(8191);
        $embedding->method('embedText')->willReturn([0.001, 0.002, 0.003]);

        $service = new IndexManagementService($this->connectionFactory, $embedding);
        $document = $this->createDocumentWithContent('Test content');

        $service->addDocument($document);

        self::assertSame(
            [0.001, 0.002, 0.003],
            $document->getFields()[SchemaManager::VECTOR_FIELD_NAME],
        );
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

        $service = new IndexManagementService($this->connectionFactory, null, $registry);
        $service->reindexAll();
    }
}
