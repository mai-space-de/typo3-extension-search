<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Middleware;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Service\ChatProviderInterface;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Domain\Model\ChatResponse;
use Maispace\MaiSearch\Http\RagApiSupport;
use Maispace\MaiSearch\Middleware\SearchAnswerMiddleware;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\ProviderAvailabilityService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class SearchAnswerMiddlewareTest extends TestCase
{
    private SearchService&MockObject $searchService;
    private RagContextResolver&MockObject $ragContextResolver;
    private ChatProviderInterface&MockObject $chatProvider;
    private ProviderAvailabilityService&MockObject $providerAvailability;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;
    private SearchSynthesisService $synthesisService;

    private string $capturedJson = '';

    private function makeMiddleware(array $ragSettings = []): SearchAnswerMiddleware
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn($ragSettings);

        return new SearchAnswerMiddleware(
            $this->responseFactory,
            $this->streamFactory,
            $this->searchService,
            $this->ragContextResolver,
            $this->synthesisService,
            new RagConfiguration($extensionConfiguration),
            $this->providerAvailability,
            new RagApiSupport(),
        );
    }

    protected function setUp(): void
    {
        $this->searchService = $this->createMock(SearchService::class);
        $this->ragContextResolver = $this->createMock(RagContextResolver::class);
        $this->chatProvider = $this->createMock(ChatProviderInterface::class);
        $this->chatProvider->method('complete')->willReturn(new ChatResponse('Hello'));

        $providerRegistry = $this->createMock(ProviderRegistry::class);
        $providerRegistry->method('getActiveChatProvider')->willReturn($this->chatProvider);

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('maisearch_answer')->willReturn($cache);
        $ragConfiguration = $this->createMock(RagConfiguration::class);
        $ragConfiguration->method('getAnswerCacheTtl')->willReturn(3600);
        $this->synthesisService = new SearchSynthesisService(
            $providerRegistry,
            new AnswerCacheService($cacheManager),
            $ragConfiguration,
        );

        $this->providerAvailability = $this->createMock(ProviderAvailabilityService::class);
        $this->providerAvailability->method('isChatProviderAvailable')->willReturn(true);
        $this->capturedJson = '';

        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->streamFactory->method('createStream')->willReturnCallback(
            function (string $content): StreamInterface {
                $this->capturedJson = $content;
                $stream = $this->createMock(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);

                return $stream;
            },
        );

        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->responseFactory->method('createResponse')->willReturnCallback(
            function (int $code = 200): ResponseInterface {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn($code);
                $response->method('withHeader')->willReturnSelf();
                $response->method('withBody')->willReturnSelf();

                return $response;
            },
        );
    }

    private function request(array $body = []): ServerRequestInterface&MockObject
    {
        $uri = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $uri->method('getPath')->willReturn('/api/search/answer');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getAttribute')->with('language')->willReturn(null);

        return $request;
    }

    #[Test]
    public function shouldHandleMatchesPostToAnswerPath(): void
    {
        self::assertTrue($this->makeMiddleware()->shouldHandle($this->request()));
    }

    #[Test]
    public function handleReturns503WhenChatProviderUnavailable(): void
    {
        $this->providerAvailability = $this->createMock(ProviderAvailabilityService::class);
        $this->providerAvailability->method('isChatProviderAvailable')->willReturn(false);

        $response = $this->makeMiddleware()->handle($this->request(['query' => 'test']));

        self::assertSame(503, $response->getStatusCode());
    }

    #[Test]
    public function handleReturns400ForEmptyQuery(): void
    {
        $response = $this->makeMiddleware()->handle($this->request(['query' => '  ']));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function handleUsesClientContextWithoutResearching(): void
    {
        $this->searchService->expects(self::never())->method('search');
        $this->ragContextResolver->expects(self::never())->method('resolve');

        $response = $this->makeMiddleware(['ragRerankEnabled' => '0'])->handle($this->request([
            'query' => 'test',
            'context' => [
                ['url' => '/a', 'title' => 'A', 'snippet' => 's', 'type' => 'page', 'score' => 1.0],
            ],
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($this->capturedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Hello', $payload['answer']);
        self::assertSame('/a', $payload['sources'][0]['url']);
    }

    #[Test]
    public function handleSearchesWhenContextMissing(): void
    {
        $this->searchService
            ->expects(self::once())
            ->method('search')
            ->willReturn(new SearchResultPage([
                new SearchResult('page', 'A', 's', '/a', '', null, 1.0),
            ], 1));

        $response = $this->makeMiddleware(['ragRerankEnabled' => '0'])->handle($this->request([
            'query' => 'test',
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode($this->capturedJson, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Hello', $payload['answer']);
    }

    #[Test]
    public function handleReturns404WhenNoContext(): void
    {
        $this->searchService->method('search')->willReturn(new SearchResultPage([], 0));

        $response = $this->makeMiddleware()->handle($this->request(['query' => 'test']));

        self::assertSame(404, $response->getStatusCode());
    }
}
