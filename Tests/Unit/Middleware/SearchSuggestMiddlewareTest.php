<?php

declare(strict_types=1);

namespace Maispace\MaiSearch\Tests\Unit\Middleware;

use Maispace\MaiSearch\Configuration\SuggestConfiguration;
use Maispace\MaiSearch\Middleware\SearchSuggestMiddleware;
use Maispace\MaiSearch\Suggest\SuggestInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class SearchSuggestMiddlewareTest extends TestCase
{
    private ResponseFactoryInterface&MockObject $responseFactory;
    private StreamFactoryInterface&MockObject $streamFactory;

    /** @var mixed captured JSON string passed to the last createStream() call */
    private mixed $capturedBody = null;

    /** @var int captured HTTP status passed to the last createResponse() call */
    private int $capturedStatus = 0;

    protected function setUp(): void
    {
        $this->capturedBody = null;
        $this->capturedStatus = 0;

        $this->responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $this->responseFactory->method('createResponse')->willReturnCallback(
            function (int $code = 200): ResponseInterface {
                $this->capturedStatus = $code;
                $response = $this->createMock(ResponseInterface::class);
                $response->method('withHeader')->willReturnSelf();
                $response->method('withBody')->willReturnSelf();

                return $response;
            },
        );

        $this->streamFactory = $this->createMock(StreamFactoryInterface::class);
        $this->streamFactory->method('createStream')->willReturnCallback(
            function (string $content): StreamInterface {
                $this->capturedBody = $content;

                return $this->createMock(StreamInterface::class);
            },
        );
    }

    private function makeMiddleware(array $settings, ?SuggestInterface $suggestService): SearchSuggestMiddleware
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->with('mai_search')->willReturn($settings);

        return new SearchSuggestMiddleware(
            $this->responseFactory,
            $this->streamFactory,
            new SuggestConfiguration($extensionConfiguration),
            $suggestService,
        );
    }

    private function request(
        string $path = '/api/search/suggest',
        string $method = 'GET',
        array $query = [],
        ?\TYPO3\CMS\Core\Site\Entity\SiteLanguage $language = null,
    ): ServerRequestInterface&MockObject {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getUri')->willReturn($uri);
        $request->method('getMethod')->willReturn($method);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getAttribute')->with('language')->willReturn($language);

        return $request;
    }

    // ── shouldHandle() ────────────────────────────────────────────────────────

    #[Test]
    public function shouldHandleMatchesGetToTheSuggestPath(): void
    {
        $middleware = $this->makeMiddleware([], null);

        self::assertTrue($middleware->shouldHandle($this->request('/api/search/suggest', 'GET')));
    }

    #[Test]
    public function shouldHandleRejectsOtherPaths(): void
    {
        $middleware = $this->makeMiddleware([], null);

        self::assertFalse($middleware->shouldHandle($this->request('/api/search/other', 'GET')));
    }

    #[Test]
    public function shouldHandleRejectsNonGetMethods(): void
    {
        $middleware = $this->makeMiddleware([], null);

        self::assertFalse($middleware->shouldHandle($this->request('/api/search/suggest', 'POST')));
    }

    // ── handle() ──────────────────────────────────────────────────────────────

    #[Test]
    public function handleReturnsEmptySuggestionsWhenDisabled(): void
    {
        $suggestService = $this->createMock(SuggestInterface::class);
        $suggestService->expects(self::never())->method('getSuggestions');

        $middleware = $this->makeMiddleware(['searchSuggestEnabled' => '0'], $suggestService);
        $middleware->handle($this->request(query: ['q' => 'gymna']));

        self::assertSame('{"suggestions":[]}', $this->capturedBody);
        self::assertSame(404, $this->capturedStatus);
    }

    #[Test]
    public function handleReturnsEmptySuggestionsWhenNoSuggestServiceIsActive(): void
    {
        $middleware = $this->makeMiddleware(['searchSuggestEnabled' => '1'], null);
        $middleware->handle($this->request(query: ['q' => 'gymna']));

        self::assertSame('{"suggestions":[]}', $this->capturedBody);
        self::assertSame(404, $this->capturedStatus);
    }

    #[Test]
    public function handleReturnsEmptySuggestionsWhenQueryIsShorterThanMinChars(): void
    {
        $suggestService = $this->createMock(SuggestInterface::class);
        $suggestService->expects(self::never())->method('getSuggestions');

        $middleware = $this->makeMiddleware(
            ['searchSuggestEnabled' => '1', 'searchSuggestMinChars' => '3'],
            $suggestService,
        );
        $middleware->handle($this->request(query: ['q' => 'gy']));

        self::assertSame('{"suggestions":[]}', $this->capturedBody);
        self::assertSame(200, $this->capturedStatus);
    }

    #[Test]
    public function handleReturnsSuggestionsFromTheActiveSuggestService(): void
    {
        $suggestService = $this->createMock(SuggestInterface::class);
        $suggestService->expects(self::once())
            ->method('getSuggestions')
            ->with('gymna', 8)
            ->willReturn(['Gymnastics for kids', 'Gymnastics for adults']);

        $middleware = $this->makeMiddleware(['searchSuggestEnabled' => '1'], $suggestService);
        $middleware->handle($this->request(query: ['q' => 'gymna']));

        self::assertSame(
            '{"suggestions":["Gymnastics for kids","Gymnastics for adults"]}',
            $this->capturedBody,
        );
        self::assertSame(200, $this->capturedStatus);
    }

    #[Test]
    public function handlePassesTheConfiguredLimitToTheSuggestService(): void
    {
        $suggestService = $this->createMock(SuggestInterface::class);
        $suggestService->expects(self::once())
            ->method('getSuggestions')
            ->with('gymna', 3)
            ->willReturn([]);

        $middleware = $this->makeMiddleware(
            ['searchSuggestEnabled' => '1', 'searchSuggestLimit' => '3'],
            $suggestService,
        );
        $middleware->handle($this->request(query: ['q' => 'gymna']));
    }

    #[Test]
    public function handlePassesTheResolvedLanguageToTheSuggestService(): void
    {
        $language = $this->createMock(\TYPO3\CMS\Core\Site\Entity\SiteLanguage::class);

        $suggestService = $this->createMock(SuggestInterface::class);
        $suggestService->expects(self::once())
            ->method('getSuggestions')
            ->with('gymna', 8, $language)
            ->willReturn([]);

        $middleware = $this->makeMiddleware(['searchSuggestEnabled' => '1'], $suggestService);
        $middleware->handle($this->request(query: ['q' => 'gymna'], language: $language));
    }
}
