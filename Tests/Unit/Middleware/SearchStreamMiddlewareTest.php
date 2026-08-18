<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Middleware;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Configuration\RagPromptFactory;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Domain\Service\StreamingChatProviderInterface;
use Maispace\MaiSearch\Http\RagApiSupport;
use Maispace\MaiSearch\Http\SseStream;
use Maispace\MaiSearch\Middleware\SearchStreamMiddleware;
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

final class SearchStreamMiddlewareTest extends TestCase
{
    private SearchService&MockObject $searchService;
    private RagContextResolver&MockObject $ragContextResolver;
    private ProviderRegistry&MockObject $providerRegistry;
    private ProviderAvailabilityService&MockObject $providerAvailability;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;

    /** @var array<int, ResponseInterface&MockObject> */
    private array $createdResponses = [];

    /** @var mixed captured argument passed to the last withBody() call */
    private mixed $capturedBody = null;

    private function makeMiddleware(array $ragSettings = [], ?FrontendInterface $answerCache = null): SearchStreamMiddleware
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn($ragSettings);
        $ragConfiguration = new RagConfiguration($extensionConfiguration);

        // Defaults to an always-miss cache so existing tests exercise the live
        // streaming path unchanged; pass $answerCache to simulate a cache hit.
        $cache = $answerCache;
        if ($cache === null) {
            $cache = $this->createMock(FrontendInterface::class);
            $cache->method('get')->willReturn(false);
        }
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->with('maisearch_answer')->willReturn($cache);

        return new SearchStreamMiddleware(
            $this->responseFactory,
            $this->streamFactory,
            $this->searchService,
            $this->ragContextResolver,
            new SearchSynthesisService($this->createMock(ProviderRegistry::class), new AnswerCacheService($cacheManager), $ragConfiguration),
            $this->providerRegistry,
            $ragConfiguration,
            $this->providerAvailability,
            new RagApiSupport(),
            new RagPromptFactory(),
        );
    }

    protected function setUp(): void
    {
        $this->searchService = $this->createMock(SearchService::class);
        $this->ragContextResolver = $this->createMock(RagContextResolver::class);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
        $this->providerAvailability = $this->createMock(ProviderAvailabilityService::class);
        $this->providerAvailability->method('isChatProviderAvailable')->willReturn(true);
        $this->capturedBody = null;

        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->responseFactory->method('createResponse')->willReturnCallback(
            function (int $code = 200): ResponseInterface {
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn($code);
                $response->method('withHeader')->willReturnSelf();
                $response->method('withBody')->willReturnCallback(
                    function (StreamInterface $body) use ($response): ResponseInterface {
                        $this->capturedBody = $body;

                        return $response;
                    },
                );

                return $response;
            },
        );

        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->streamFactory->method('createStream')->willReturnCallback(
            function (string $content): StreamInterface {
                $stream = $this->createMock(StreamInterface::class);
                $stream->method('__toString')->willReturn($content);

                return $stream;
            },
        );
    }

    private function request(string $path = '/api/search/stream', string $method = 'POST', array $body = []): ServerRequestInterface&MockObject
    {
        $uri = $this->createMock(\Psr\Http\Message\UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR));

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')->willReturn('application/json');
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => null,
        );

        return $request;
    }

    private function makeResult(string $title = 'Result'): SearchResult
    {
        return new SearchResult('page', $title, 'snippet', '/url', 'icon', null, 1.0);
    }

    // ── shouldHandle() ────────────────────────────────────────────────────────

    #[Test]
    public function shouldHandleMatchesPostToTheStreamPath(): void
    {
        $middleware = $this->makeMiddleware();

        self::assertTrue($middleware->shouldHandle($this->request('/api/search/stream', 'POST')));
    }

    #[Test]
    public function shouldHandleRejectsOtherPaths(): void
    {
        $middleware = $this->makeMiddleware();

        self::assertFalse($middleware->shouldHandle($this->request('/api/search/other', 'POST')));
    }

    #[Test]
    public function shouldHandleRejectsNonPostMethods(): void
    {
        $middleware = $this->makeMiddleware();

        self::assertFalse($middleware->shouldHandle($this->request('/api/search/stream', 'GET')));
    }

    // ── handle() guard clauses ───────────────────────────────────────────────

    #[Test]
    public function handleReturns404WhenStreamingDisabled(): void
    {
        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '0']);

        $response = $middleware->handle($this->request(body: ['query' => 'test']));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function handleReturns400ForAnEmptyQuery(): void
    {
        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1']);

        $response = $middleware->handle($this->request(body: ['query' => '   ']));

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function handleReturns501WhenActiveProviderCannotStream(): void
    {
        $this->providerRegistry->method('getActiveChatProvider')
            ->willReturn($this->createMock(\Maispace\MaiSearch\Domain\Service\ChatProviderInterface::class));

        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1']);

        $response = $middleware->handle($this->request(body: ['query' => 'test']));

        self::assertSame(501, $response->getStatusCode());
    }

    #[Test]
    public function handleEmitsErrorWhenNoContextIsFound(): void
    {
        $this->providerRegistry->method('getActiveChatProvider')
            ->willReturn($this->createMock(StreamingChatProviderInterface::class));
        $this->searchService->method('search')->willReturn(new SearchResultPage([], 0));

        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1']);
        $response = $middleware->handle($this->request(body: ['query' => 'test']));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(SseStream::class, $this->capturedBody);

        ob_start();
        $this->capturedBody->emit();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('event: error', $output);
        self::assertStringNotContainsString('event: done', $output);
    }

    // ── success path ─────────────────────────────────────────────────────────

    #[Test]
    public function handleUsesPlainSearchWhenRerankIsDisabled(): void
    {
        $chatProvider = $this->createMock(StreamingChatProviderInterface::class);
        $chatProvider->method('stream')->willReturn((static fn(): \Generator => yield from ['answer'])());
        $this->providerRegistry->method('getActiveChatProvider')->willReturn($chatProvider);

        $this->searchService
            ->expects(self::once())
            ->method('search')
            ->with('test', 5, 0, null, true, null)
            ->willReturn(new SearchResultPage([$this->makeResult()], 1));
        $this->ragContextResolver->expects(self::never())->method('resolve');

        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1', 'ragRerankEnabled' => '0']);
        $response = $middleware->handle($this->request(body: ['query' => 'test']));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(SseStream::class, $this->capturedBody);

        ob_start();
        $this->capturedBody->emit();
        ob_get_clean();
    }

    #[Test]
    public function handleDelegatesToRagContextResolverWhenRerankIsEnabled(): void
    {
        $chatProvider = $this->createMock(StreamingChatProviderInterface::class);
        $chatProvider->method('stream')->willReturn((static fn(): \Generator => yield from ['answer'])());
        $this->providerRegistry->method('getActiveChatProvider')->willReturn($chatProvider);

        $this->searchService->expects(self::never())->method('search');
        $this->ragContextResolver
            ->expects(self::once())
            ->method('resolve')
            ->with('test', null, null, 5, 20)
            ->willReturn([$this->makeResult()]);

        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1', 'ragRerankEnabled' => '1']);
        $response = $middleware->handle($this->request(body: ['query' => 'test']));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(SseStream::class, $this->capturedBody);

        ob_start();
        $this->capturedBody->emit();
        ob_get_clean();
    }

    #[Test]
    public function handleReturnsAnSseStreamThatEmitsTokensAndADoneEvent(): void
    {
        $chatProvider = $this->createMock(StreamingChatProviderInterface::class);
        $chatProvider->method('stream')->willReturn((static fn(): \Generator => yield from ['Hel', 'lo'])());
        $this->providerRegistry->method('getActiveChatProvider')->willReturn($chatProvider);
        $this->searchService->method('search')->willReturn(new SearchResultPage([$this->makeResult('Doc A')], 1));

        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1']);
        $middleware->handle($this->request(body: ['query' => 'test']));

        self::assertInstanceOf(SseStream::class, $this->capturedBody);

        ob_start();
        $this->capturedBody->emit();
        $output = ob_get_clean();

        self::assertStringContainsString('"answer":"Hello"', $output);
        self::assertStringContainsString('"title":"Doc A"', $output);
        self::assertStringContainsString('"role":"user"', $output);
        self::assertStringContainsString('"content":"test"', $output);
    }

    // ── answer cache (SPEC-v3-caching-chunking.md Phase C) ──────────────────────

    #[Test]
    public function handleServesCachedAnswerAsSseBurstWithoutCallingTheProvider(): void
    {
        $chatProvider = $this->createMock(StreamingChatProviderInterface::class);
        $chatProvider->expects(self::never())->method('stream');
        $this->providerRegistry->method('getActiveChatProvider')->willReturn($chatProvider);
        $this->searchService->method('search')->willReturn(new SearchResultPage([$this->makeResult('Doc A')], 1));

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn('Cached answer text.');
        $cache->expects(self::never())->method('set');

        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1'], $cache);
        $response = $middleware->handle($this->request(body: ['query' => 'test']));

        self::assertSame(200, $response->getStatusCode());
        self::assertInstanceOf(SseStream::class, $this->capturedBody);

        ob_start();
        $this->capturedBody->emit();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('"phase":"cached"', $output);
        self::assertStringContainsString('"answer":"Cached answer text."', $output);
        self::assertStringNotContainsString('event: error', $output);
    }

    #[Test]
    public function handleCachesTheAnswerAfterALiveGeneration(): void
    {
        $chatProvider = $this->createMock(StreamingChatProviderInterface::class);
        $chatProvider->method('stream')->willReturn((static fn(): \Generator => yield from ['Hel', 'lo'])());
        $this->providerRegistry->method('getActiveChatProvider')->willReturn($chatProvider);
        $this->searchService->method('search')->willReturn(new SearchResultPage([$this->makeResult('Doc A')], 1));

        $cache = $this->createMock(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cache->expects(self::once())->method('set')->with(self::isType('string'), 'Hello', self::anything(), self::anything());

        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1'], $cache);
        $middleware->handle($this->request(body: ['query' => 'test']));

        ob_start();
        $this->capturedBody->emit();
        ob_get_clean();
    }
}
