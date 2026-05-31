<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Domain\Service;

use ApacheSolrForTypo3\Solr\Domain\Search\Query\Query;
use ApacheSolrForTypo3\Solr\System\Solr\ResponseAdapter;
use ApacheSolrForTypo3\Solr\System\Solr\Service\SolrReadService;
use ApacheSolrForTypo3\Solr\System\Solr\SolrConnection;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Service\SearchResultFormatterInterface;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\VectorEmbeddingInterface;
use Maispace\MaiSearch\Domain\Solr\ConnectionFactory;
use Maispace\MaiSearch\Domain\Solr\SchemaManager;
use Maispace\MaiSearch\Service\ResultFormatterRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

class SearchServiceTest extends TestCase
{
    private ConnectionFactory $connectionFactory;
    private ResultFormatterRegistry $resultFormatterRegistry;
    private SearchService $searchService;

    protected function setUp(): void
    {
        $this->connectionFactory = $this->createMock(ConnectionFactory::class);
        $this->resultFormatterRegistry = $this->createMock(ResultFormatterRegistry::class);
        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
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
        int $uid = 1,
        string $date = '2026-05-23T12:00:00Z',
        float $score = 0.95,
    ): array {
        return [
            'type_s' => $type,
            'title_s' => $title,
            'content_t' => $content,
            'url_s' => $url,
            'uid_i' => $uid,
            'crdate_dt' => $date,
            'score' => $score,
        ];
    }

    /**
     * @param list<array<string, mixed>> $docs
     */
    private function createResponseAdapter(array $docs): ResponseAdapter
    {
        $responseJson = json_encode([
            'response' => [
                'docs' => $docs,
            ],
        ]);
        self::assertNotFalse($responseJson, 'Failed to encode test response JSON');

        return new ResponseAdapter($responseJson, 200, 'OK');
    }

    private function mockSolrConnection(ResponseAdapter $response): SolrConnection
    {
        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->method('search')
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection
            ->method('getReadService')
            ->willReturn($readService);

        return $connection;
    }

    #[Test]
    public function searchReturnsEmptyArrayForEmptyQuery(): void
    {
        $result = $this->searchService->search('');

        self::assertSame([], $result);
    }

    #[Test]
    public function searchReturnsEmptyArrayForWhitespaceQuery(): void
    {
        $result = $this->searchService->search('   ');

        self::assertSame([], $result);
    }

    #[Test]
    public function searchPassesLimitAndOffsetToSolrQuery(): void
    {
        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                return $query->getQuery() === '(title_t:(test) OR content_t:(test))'
                    && $query->getRows() === 10
                    && $query->getStart() === 5;
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection
            ->method('getReadService')
            ->willReturn($readService);

        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($connection);

        $this->searchService->search('test', 10, 5);
    }

    #[Test]
    public function searchReturnsMappedSearchResults(): void
    {
        $formatter = $this->createMock(SearchResultFormatterInterface::class);
        $formatter
            ->method('getType')
            ->willReturn('news');
        $formatter
            ->method('formatResult')
            ->willReturnCallback(function (array $doc): SearchResult {
                return new SearchResult(
                    type: 'news',
                    title: $doc['title_s'] ?? '',
                    snippet: $doc['content_t'] ?? '',
                    url: $doc['url_s'] ?? '',
                    icon: 'news-icon',
                    date: new \DateTime('2026-05-23T12:00:00Z'),
                    score: (float) ($doc['score'] ?? 0.0),
                );
            });

        $this->resultFormatterRegistry
            ->method('getFormatter')
            ->with('news')
            ->willReturn($formatter);

        $response = $this->createResponseAdapter([
            $this->createSolrDocArray(
                type: 'news',
                title: 'News Title',
                content: 'News content',
                url: '/news/slug',
                uid: 42,
                score: 0.85,
            ),
        ]);

        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->mockSolrConnection($response));

        $results = $this->searchService->search('news');

        self::assertCount(1, $results);
        self::assertSame('news', $results[0]->type);
        self::assertSame('News Title', $results[0]->title);
        self::assertSame('News content', $results[0]->snippet);
        self::assertSame('/news/slug', $results[0]->url);
        self::assertSame('news-icon', $results[0]->icon);
        self::assertSame(0.85, $results[0]->score);
    }

    #[Test]
    public function searchSkipsDocumentWithoutTypeField(): void
    {
        $response = $this->createResponseAdapter([
            [
                'title_s' => 'No Type',
                'content_t' => 'Missing type_s',
                'url_s' => '/no-type',
            ],
        ]);

        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->mockSolrConnection($response));

        $results = $this->searchService->search('test');

        self::assertSame([], $results);
    }

    #[Test]
    public function searchSkipsDocumentWithUnregisteredType(): void
    {
        $this->resultFormatterRegistry
            ->method('getFormatter')
            ->with('unknown_type')
            ->willReturn(null);

        $response = $this->createResponseAdapter([
            $this->createSolrDocArray(type: 'unknown_type', title: 'Unknown'),
        ]);

        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->mockSolrConnection($response));

        $results = $this->searchService->search('test');

        self::assertSame([], $results);
    }

    #[Test]
    public function searchUsesFormatterFromRegistry(): void
    {
        $formatter = $this->createMock(SearchResultFormatterInterface::class);
        $formatter
            ->method('getType')
            ->willReturn('jobs');
        $formatter
            ->expects(self::once())
            ->method('formatResult')
            ->willReturnCallback(fn(array $doc) => new SearchResult(
                type: 'jobs',
                title: $doc['title_s'] ?? '',
                snippet: '',
                url: '',
                icon: '',
                date: null,
                score: 0.0,
            ));

        $this->resultFormatterRegistry
            ->expects(self::once())
            ->method('getFormatter')
            ->with('jobs')
            ->willReturn($formatter);

        $response = $this->createResponseAdapter([
            $this->createSolrDocArray(type: 'jobs', title: 'Job Posting'),
        ]);

        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->mockSolrConnection($response));

        $results = $this->searchService->search('jobs');

        self::assertCount(1, $results);
        self::assertSame('jobs', $results[0]->type);
    }

    #[Test]
    public function searchReturnsEmptyArrayWhenResponseHasNoDocs(): void
    {
        $responseJson = json_encode([
            'response' => [
                'numFound' => 0,
            ],
        ]);
        self::assertNotFalse($responseJson);

        $response = new ResponseAdapter($responseJson, 200, 'OK');

        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->mockSolrConnection($response));

        $results = $this->searchService->search('test');

        self::assertSame([], $results);
    }

    #[Test]
    public function searchReturnsEmptyArrayForNullResponseBody(): void
    {
        $response = new ResponseAdapter(null, 500, 'Internal Server Error');

        $this->connectionFactory
            ->method('getConnection')
            ->willReturn($this->mockSolrConnection($response));

        $results = $this->searchService->search('test');

        self::assertSame([], $results);
    }

    #[Test]
    public function searchPassesSiteLanguageToConnectionFactory(): void
    {
        $language = $this->createMock(SiteLanguage::class);
        $locale = new \TYPO3\CMS\Core\Localization\Locale('de');
        $language->method('getLocale')->willReturn($locale);

        $response = $this->createResponseAdapter([]);

        $this->connectionFactory
            ->expects(self::once())
            ->method('getConnection')
            ->with($language)
            ->willReturn($this->mockSolrConnection($response));

        $this->searchService->search('test', 20, 0, $language);
    }

    #[Test]
    public function searchDoesNotAddRqParamWhenRagDisabled(): void
    {
        $embeddingService = $this->createMock(VectorEmbeddingInterface::class);
        $embeddingService->expects(self::never())
            ->method('embedText');

        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $embeddingService,
        );

        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                $params = $query->getParams();
                return !isset($params['rq']);
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('hybrid test', 20, 0, null, false);
    }

    #[Test]
    public function searchDoesNotAddRqParamWhenEmbeddingServiceIsNull(): void
    {
        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                $params = $query->getParams();
                return !isset($params['rq']);
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('hybrid test', 20, 0, null, true);
    }

    #[Test]
    public function searchAddsRqParamWhenRagEnabled(): void
    {
        $queryVector = array_fill(0, 1536, 0.001);
        $embeddingService = $this->createMock(VectorEmbeddingInterface::class);
        $embeddingService
            ->method('embedText')
            ->with('hybrid test')
            ->willReturn($queryVector);

        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $embeddingService,
        );

        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                $params = $query->getParams();
                if (!isset($params['rq'])) {
                    return false;
                }
                $rqValue = $params['rq'];
                return str_starts_with((string) $rqValue, '{!knn f=content_vector topK=100}[');
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('hybrid test', 20, 0, null, true);
    }

    #[Test]
    public function searchRqParamIncludesVectorValues(): void
    {
        $queryVector = [0.1, 0.2, 0.3];
        $embeddingService = $this->createMock(VectorEmbeddingInterface::class);
        $embeddingService
            ->method('embedText')
            ->willReturn($queryVector);

        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $embeddingService,
        );

        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query) use ($queryVector): bool {
                $params = $query->getParams();
                $rqValue = (string) ($params['rq'] ?? '');
                $expectedVectorString = '[' . implode(',', $queryVector) . ']';
                return str_contains($rqValue, $expectedVectorString);
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('semantic query', 20, 0, null, true);
    }

    #[Test]
    public function searchFallsBackWhenEmbeddingApiFails(): void
    {
        $embeddingService = $this->createMock(VectorEmbeddingInterface::class);
        $embeddingService
            ->method('embedText')
            ->willThrowException(new \RuntimeException('API unavailable'));

        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $embeddingService,
        );

        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                $params = $query->getParams();
                return !isset($params['rq']);
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('fallback query', 20, 0, null, true);
    }

    #[Test]
    public function searchRqParamWithCustomWeightAndTopK(): void
    {
        $queryVector = [0.5, 0.6];
        $embeddingService = $this->createMock(VectorEmbeddingInterface::class);
        $embeddingService
            ->method('embedText')
            ->willReturn($queryVector);

        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $embeddingService,
        );

        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                $params = $query->getParams();
                $rqValue = (string) ($params['rq'] ?? '');
                return str_contains($rqValue, 'topK=50')
                    && str_contains($rqValue, '^0.5');
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('weighted query', 20, 0, null, true, 50, 0.5);
    }

    #[Test]
    public function searchRqParamOmitsWeightWhenDefault(): void
    {
        $queryVector = [0.1];
        $embeddingService = $this->createMock(VectorEmbeddingInterface::class);
        $embeddingService
            ->method('embedText')
            ->willReturn($queryVector);

        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $embeddingService,
        );

        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                $params = $query->getParams();
                $rqValue = (string) ($params['rq'] ?? '');
                return !str_contains($rqValue, '^');
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('default weight', 20, 0, null, true, 100, 1.0);
    }

    #[Test]
    public function searchSkipsKnnWhenTopKIsZero(): void
    {
        $embeddingService = $this->createMock(VectorEmbeddingInterface::class);
        $embeddingService->expects(self::never())
            ->method('embedText');

        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $embeddingService,
        );

        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                $params = $query->getParams();
                return !isset($params['rq']);
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('zero topk', 20, 0, null, true, 0);
    }

    #[Test]
    public function searchSkipsKnnWhenEmbeddingReturnsEmpty(): void
    {
        $embeddingService = $this->createMock(VectorEmbeddingInterface::class);
        $embeddingService
            ->method('embedText')
            ->willReturn([]);

        $this->searchService = new SearchService(
            $this->connectionFactory,
            $this->resultFormatterRegistry,
            $embeddingService,
        );

        $response = $this->createResponseAdapter([]);

        $readService = $this->createMock(SolrReadService::class);
        $readService
            ->expects(self::once())
            ->method('search')
            ->with(self::callback(function (Query $query): bool {
                $params = $query->getParams();
                return !isset($params['rq']);
            }))
            ->willReturn($response);

        $connection = $this->createMock(SolrConnection::class);
        $connection->method('getReadService')->willReturn($readService);

        $this->connectionFactory->method('getConnection')->willReturn($connection);

        $this->searchService->search('empty embedding', 20, 0, null, true);
    }
}
