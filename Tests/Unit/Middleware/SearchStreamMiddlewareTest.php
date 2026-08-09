<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Middleware;

use Maispace\MaiSearch\Configuration\RagConfiguration;
use Maispace\MaiSearch\Domain\Dto\SearchResult;
use Maispace\MaiSearch\Domain\Dto\SearchResultPage;
use Maispace\MaiSearch\Domain\Service\RagContextResolver;
use Maispace\MaiSearch\Domain\Service\SearchService;
use Maispace\MaiSearch\Domain\Service\SearchSynthesisService;
use Maispace\MaiSearch\Domain\Service\StreamingChatProviderInterface;
use Maispace\MaiSearch\Http\SseStream;
use Maispace\MaiSearch\Middleware\SearchStreamMiddleware;
use Maispace\MaiSearch\Service\AnswerCacheService;
use Maispace\MaiSearch\Service\ProviderRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class SearchStreamMiddlewareTest extends TestCase
{
    private SearchService&MockObject $searchService;
    private RagContextResolver&MockObject $ragContextResolver;
    private ProviderRegistry&MockObject $providerRegistry;
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;

    /** @var array<int, ResponseInterface&MockObject> */
    private array $createdResponses = [];

    /** @var mixed captured argument passed to the last withBody() call */
    private mixed $capturedBody = null;

    private function makeMiddleware(array $ragSettings = []): SearchStreamMiddleware
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn($ragSettings);
        $ragConfiguration = new RagConfiguration($extensionConfiguration);

        return new SearchStreamMiddleware(
            $this->responseFactory,
            $this->streamFactory,
            $this->searchService,
            $this->ragContextResolver,
            new SearchSynthesisService($this->createMock(ProviderRegistry::class), new AnswerCacheService($this->createMock(FrontendInterface::class))),
            $this->providerRegistry,
            $ragConfiguration,
        );
    }

    protected function setUp(): void
    {
        $this->searchService = $this->createMock(SearchService::class);
        $this->ragContextResolver = $this->createMock(RagContextResolver::class);
        $this->providerRegistry = $this->createMock(ProviderRegistry::class);
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
        $request->method('getAttribute')->with('language')->willReturn(null);

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
    public function handleReturns404WhenNoContextIsFound(): void
    {
        $this->providerRegistry->method('getActiveChatProvider')
            ->willReturn($this->createMock(StreamingChatProviderInterface::class));
        $this->searchService->method('search')->willReturn(new SearchResultPage([], 0));

        $middleware = $this->makeMiddleware(['ragStreamingEnabled' => '1']);

        $response = $middleware->handle($this->request(body: ['query' => 'test']));

        self::assertSame(404, $response->getStatusCode());
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
}
