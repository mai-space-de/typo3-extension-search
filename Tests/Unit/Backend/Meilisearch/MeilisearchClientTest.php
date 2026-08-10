<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Backend\Meilisearch;

use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchClient;
use Maispace\MaiSearch\Backend\Meilisearch\MeilisearchDocument;
use Meilisearch\Client;
use Meilisearch\Endpoints\Indexes;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Search\SearchResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class MeilisearchClientTest extends TestCase
{
    private Client&MockObject $sdkClient;
    private Indexes&MockObject $index;
    private MeilisearchClient $client;

    protected function setUp(): void
    {
        $this->sdkClient = $this->createMock(Client::class);
        $this->index = $this->createMock(Indexes::class);
        $this->sdkClient->method('index')->with('mai_search_de')->willReturn($this->index);
        $this->client = new MeilisearchClient($this->sdkClient);
    }

    #[Test]
    public function ensureIndexSkipsCreateWhenIndexAlreadyExists(): void
    {
        $this->sdkClient->expects(self::once())->method('getIndex')->with('mai_search_de');
        $this->sdkClient->expects(self::never())->method('createIndex');

        $this->client->ensureIndex('mai_search_de');
    }

    #[Test]
    public function ensureIndexCreatesIndexWhenMissing(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(404);
        $response->method('getReasonPhrase')->willReturn('Not Found');
        $this->sdkClient->method('getIndex')->willThrowException(
            new ApiException($response, '{}'),
        );
        $this->sdkClient->expects(self::once())->method('createIndex')->with('mai_search_de', ['primaryKey' => 'id']);

        $this->client->ensureIndex('mai_search_de');
    }

    #[Test]
    public function ensureIndexUpdatesFilterableAttributesWhenProvided(): void
    {
        $this->index->expects(self::once())->method('updateFilterableAttributes')->with(['type']);

        $this->client->ensureIndex('mai_search_de', ['type']);
    }

    #[Test]
    public function ensureIndexSkipsSettingsUpdateWhenNoFilterableAttributesGiven(): void
    {
        $this->index->expects(self::never())->method('updateFilterableAttributes');

        $this->client->ensureIndex('mai_search_de');
    }

    #[Test]
    public function addDocumentsMapsDocumentsToArraysBeforeSending(): void
    {
        $document = (new MeilisearchDocument())->withField('id', 'news-1')->withField('title', 'Test');

        $this->index->expects(self::once())->method('addDocuments')->with([$document->toArray()]);

        $this->client->addDocuments('mai_search_de', [$document]);
    }

    #[Test]
    public function addDocumentsSkipsCallWhenListIsEmpty(): void
    {
        $this->index->expects(self::never())->method('addDocuments');

        $this->client->addDocuments('mai_search_de', []);
    }

    #[Test]
    public function deleteDocumentDelegatesToIndex(): void
    {
        $this->index->expects(self::once())->method('deleteDocument')->with('news-1');

        $this->client->deleteDocument('mai_search_de', 'news-1');
    }

    #[Test]
    public function deleteAllDocumentsDelegatesToIndex(): void
    {
        $this->index->expects(self::once())->method('deleteAllDocuments');

        $this->client->deleteAllDocuments('mai_search_de');
    }

    #[Test]
    public function deleteDocumentsByFilterDelegatesToIndexWithFilterOption(): void
    {
        $this->index->expects(self::once())->method('deleteDocuments')->with(['filter' => 'type = chunk']);

        $this->client->deleteDocumentsByFilter('mai_search_de', 'type = chunk');
    }

    #[Test]
    public function configureEmbedderSendsAUserProvidedEmbedderDefinition(): void
    {
        $this->index
            ->expects(self::once())
            ->method('updateEmbedders')
            ->with(['default' => ['source' => 'userProvided', 'dimensions' => 1024]]);

        $this->client->configureEmbedder('mai_search_de', 'default', 1024);
    }

    #[Test]
    public function searchMapsSdkResultIntoSearchResponse(): void
    {
        $sdkResult = $this->createMock(SearchResult::class);
        $sdkResult->method('toArray')->willReturn([
            'hits' => [['id' => 'news-1', 'title' => 'Hello']],
            'estimatedTotalHits' => 1,
            'processingTimeMs' => 3,
            'facetDistribution' => ['type' => ['news' => 1]],
        ]);

        $this->index
            ->method('search')
            ->with('hello', ['limit' => 20])
            ->willReturn($sdkResult);

        $response = $this->client->search('mai_search_de', 'hello', ['limit' => 20]);

        self::assertSame([['id' => 'news-1', 'title' => 'Hello']], $response->hits);
        self::assertSame(1, $response->estimatedTotalHits);
        self::assertSame(3, $response->processingTimeMs);
        self::assertSame(['type' => ['news' => 1]], $response->facetDistribution);
    }
}
