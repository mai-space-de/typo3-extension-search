<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use ApacheSolrForTypo3\Solr\System\Solr\Document\Document;
use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrWriteService;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use Maispace\MaiSearch\Domain\Service\IndexManagementService;
use Maispace\MaiSearch\Domain\Service\VectorEmbeddingInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

final class IndexManagementServiceTest extends TestCase
{
    private ConnectionFactory&MockObject $connectionFactory;

    private SolrWriteService&MockObject $writeService;

    private IndexManagementService $service;

    protected function setUp(): void
    {
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
        $this->writeService = $this->createMock(SolrWriteService::class);

        $solrConnection = $this->createMock(SolrConnection::class);
        $solrConnection
            ->method('getWriteService')
            ->willReturn($this->writeService);

        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($solrConnection);

        $this->connectionFactory
            ->method('getConnectionForLanguageCode')
            ->willReturn($solrConnection);

        $this->service = new IndexManagementService($this->connectionFactory);
    }

    private function createServiceWithEmbedding(VectorEmbeddingInterface $embedding): IndexManagementService
    {
        return new IndexManagementService($this->connectionFactory, $embedding);
    }

    // ── addDocument() — without embedding service ────────────────────────────

    #[Test]
    public function addDocumentSendsDocumentToSolr(): void
    {
        $document = $this->createDocumentWithContent('Test content');

        $this->writeService
            ->expects(self::once())
            ->method('addDocuments')
            ->with([$document]);

        $this->writeService
            ->expects(self::once())
            ->method('commit')
            ->with(false, false);

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

    // ── addDocument() — with embedding service ───────────────────────────────

    #[Test]
    public function addDocumentWithEmbeddingAddsVectorField(): void
    {
        $embedding = $this->createMock(VectorEmbeddingInterface::class);
        $embedding
            ->method('getMaxInputTokens')
            ->willReturn(8191);
        $embedding
            ->method('embedText')
            ->willReturn([0.001, 0.002, 0.003]);

        $service = $this->createServiceWithEmbedding($embedding);
        $document = $this->createDocumentWithContent('Test content for embedding');

        $service->addDocument($document);

        $fields = $document->getFields();
        self::assertArrayHasKey(SchemaManager::VECTOR_FIELD_NAME, $fields);
        self::assertSame([0.001, 0.002, 0.003], $fields[SchemaManager::VECTOR_FIELD_NAME]);
    }

    #[Test]
    public function addDocumentSkipsEmbeddingWhenContentIsEmpty(): void
    {
        $embedding = $this->createMock(VectorEmbeddingInterface::class);
        $embedding
            ->expects(self::never())
            ->method('embedText');
        $service = $this->createServiceWithEmbedding($embedding);

        $document = new Document();
        $document->setField('id', 'test-1');
        $document->setField('content_t', '');

        $service->addDocument($document);

        self::assertArrayNotHasKey(SchemaManager::VECTOR_FIELD_NAME, $document->getFields());
    }

    #[Test]
    public function addDocumentSkipsEmbeddingWhenContentFieldIsMissing(): void
    {
        $embedding = $this->createMock(VectorEmbeddingInterface::class);
        $embedding
            ->expects(self::never())
            ->method('embedText');
        $service = $this->createServiceWithEmbedding($embedding);

        $document = new Document();
        $document->setField('id', 'test-1');

        $service->addDocument($document);

        self::assertArrayNotHasKey(SchemaManager::VECTOR_FIELD_NAME, $document->getFields());
    }

    #[Test]
    public function addDocumentSkipsEmbeddingWhenEmbeddingReturnsEmptyArray(): void
    {
        $embedding = $this->createMock(VectorEmbeddingInterface::class);
        $embedding
            ->method('getMaxInputTokens')
            ->willReturn(8191);
        $embedding
            ->method('embedText')
            ->willReturn([]);
        $service = $this->createServiceWithEmbedding($embedding);

        $document = $this->createDocumentWithContent('Test content');

        $service->addDocument($document);

        self::assertArrayNotHasKey(SchemaManager::VECTOR_FIELD_NAME, $document->getFields());
    }

    #[Test]
    public function addDocumentTruncatesContentWhenExceedingTokenLimit(): void
    {
        $embedding = $this->createMock(VectorEmbeddingInterface::class);
        $embedding
            ->method('getMaxInputTokens')
            ->willReturn(10);
        $embedding
            ->expects(self::once())
            ->method('embedText')
            ->with(self::callback(static fn(string $text): bool => mb_strlen($text) <= 40))
            ->willReturn([0.1, 0.2]);
        $service = $this->createServiceWithEmbedding($embedding);

        $longContent = str_repeat('word ', 100);
        $document = $this->createDocumentWithContent($longContent);

        $service->addDocument($document);

        $fields = $document->getFields();
        self::assertArrayHasKey(SchemaManager::VECTOR_FIELD_NAME, $fields);
    }

    #[Test]
    public function addDocumentHandlesEmbeddingApiErrorGracefully(): void
    {
        $embedding = $this->createMock(VectorEmbeddingInterface::class);
        $embedding
            ->method('getMaxInputTokens')
            ->willReturn(8191);
        $embedding
            ->method('embedText')
            ->willThrowException(new \RuntimeException('API failure'));
        $service = $this->createServiceWithEmbedding($embedding);

        $document = $this->createDocumentWithContent('Test content');

        $service->addDocument($document);

        self::assertArrayNotHasKey(SchemaManager::VECTOR_FIELD_NAME, $document->getFields());
    }

    // ── addDocumentForLanguageCode() ─────────────────────────────────────────

    #[Test]
    public function addDocumentForLanguageCodeUsesLanguageCodeConnection(): void
    {
        $document = $this->createDocumentWithContent('Test');

        $this->connectionFactory
            ->expects(self::once())
            ->method('getConnectionForLanguageCode')
            ->with('de');

        $this->service->addDocumentForLanguageCode($document, 'de');
    }

    #[Test]
    public function addDocumentForLanguageCodeFallsBackToDefaultConnection(): void
    {
        $document = $this->createDocumentWithContent('Test');

        $this->connectionFactory
            ->expects(self::once())
            ->method('getConnection');

        $this->service->addDocumentForLanguageCode($document);
    }

    #[Test]
    public function addDocumentForLanguageCodeAddsVectorWhenEmbeddingAvailable(): void
    {
        $embedding = $this->createMock(VectorEmbeddingInterface::class);
        $embedding
            ->method('getMaxInputTokens')
            ->willReturn(8191);
        $embedding
            ->method('embedText')
            ->willReturn([0.5, 0.6, 0.7]);
        $service = $this->createServiceWithEmbedding($embedding);

        $document = $this->createDocumentWithContent('Test content for vector');

        $service->addDocumentForLanguageCode($document, 'en');

        $fields = $document->getFields();
        self::assertArrayHasKey(SchemaManager::VECTOR_FIELD_NAME, $fields);
        self::assertSame([0.5, 0.6, 0.7], $fields[SchemaManager::VECTOR_FIELD_NAME]);
    }

    // ── delete / clear operations ────────────────────────────────────────────

    #[Test]
    public function deleteRecordSendsDeleteQuery(): void
    {
        $this->writeService
            ->expects(self::once())
            ->method('deleteByQuery')
            ->with('id:page-42');

        $this->service->deleteRecord('page', 42);
    }

    #[Test]
    public function deleteByTypeSendsDeleteByType(): void
    {
        $this->writeService
            ->expects(self::once())
            ->method('deleteByType')
            ->with('news', false);

        $this->service->deleteByType('news');
    }

    #[Test]
    public function clearIndexSendsDeleteAll(): void
    {
        $this->writeService
            ->expects(self::once())
            ->method('deleteByQuery')
            ->with('*:*');

        $this->service->clearIndex();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function createDocumentWithContent(string $content): Document
    {
        $document = new Document();
        $document->setField('id', 'test-1');
        $document->setField('content_t', $content);

        return $document;
    }


}
